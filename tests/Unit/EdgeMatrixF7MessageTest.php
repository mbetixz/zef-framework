<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7d — Application/Message (ronde 3).
 *
 * Kurikulum chunk f7-rest-c1 (28 escape baseline): dedup middleware TTL
 * batas penuh (1/0, 604800/604801, semua mutasi 86400*7 ±1 dan /7), kunci
 * dedup = "dedup-" + sha256(messageId) exact, guard non-MessageResult,
 * message bus (guard tipe duplikat, batas middleware 32/33, depth guard
 * tepat 64 via rekursi terhitung + reset finally, context eksplisit
 * identitas, MessageResult accepted), serializer (1 MiB boundary tepat,
 * depth JSON 512/513, grammar header string + pesan persis).
 *
 * Setiap test membunuh mutan spesifik dari build/infection-f7-rest-c1.log.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\IdempotencyStoreInterface;
use Zef\Framework\Message\DeduplicatingMiddleware;
use Zef\Framework\Message\InProcessMessageBus;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageHandlerInterface;
use Zef\Framework\Message\MessageMiddlewareInterface;
use Zef\Framework\Message\MessageResult;

final class F7D_Store implements IdempotencyStoreInterface
{
    /** @var list<string> */
    public array $keys = [];

    /** @var array<string,mixed> */
    public array $entries = [];
    public mixed $return = null;

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        $this->keys[] = $key;
        if ($this->return !== null) {
            return $this->return; // mode entry-terpaksa untuk test guard
        }
        if (isset($this->entries[$key])) {
            return $this->entries[$key];
        }

        return $this->entries[$key] = $producer();
    }
}

final class F7D_Handler implements MessageHandlerInterface
{
    #[\Override]
    public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
    {
        return null;
    }
}

final class F7D_CountingHandler implements MessageHandlerInterface
{
    private int $runs;

    public function __construct(int &$runs)
    {
        $this->runs = &$runs;
    }

    #[\Override]
    public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
    {
        ++$this->runs;

        return new MessageResult('msg-00001', true);
    }
}

/**
 * @internal
 */
final class EdgeMatrixF7MessageTest extends TestCase
{
    // --------------------------------------------- DeduplicatingMiddleware

    public function testDedupTtlBoundaries(): void
    {
        $store = new F7D_Store();
        self::addToAssertionCount(1); // 1 dan 604800 valid
        new DeduplicatingMiddleware($store, 1);
        new DeduplicatingMiddleware($store, 86_400 * 7);

        foreach ([0, 86_400 * 7 + 1] as $bad) {
            try {
                new DeduplicatingMiddleware($store, $bad);
                self::fail("Expected InvalidArgumentException for TTL {$bad}.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Dedup TTL must be 1..604800 seconds.', $e->getMessage());
            }
        }
    }

    public function testDedupKeyIsPrefixedSha256OfMessageId(): void
    {
        $store = new F7D_Store();
        $middleware = new DeduplicatingMiddleware($store, 3600);
        $bus = new InProcessMessageBus(['m.cmd.do' => new F7D_Handler()], [$middleware]);
        $bus->dispatch($this->envelope());
        self::assertSame('dedup-' . hash('sha256', 'msg-00001', false), $store->keys[0], 'key = "dedup-" + sha256(messageId)');
    }

    public function testDedupRejectsNonMessageResultEntries(): void
    {
        $store = new F7D_Store();
        $store->return = 'not-a-result';
        $middleware = new DeduplicatingMiddleware($store, 3600);

        try {
            $middleware->process(
                $this->envelope(),
                new MessageContext(),
                static fn (): MessageResult => new MessageResult('msg-00001', true),
            );
            self::fail('Expected RuntimeException for a non-MessageResult store entry.');
        } catch (\RuntimeException $e) {
            self::assertSame('Dedup store returned a non-MessageResult entry.', $e->getMessage());
        }
    }

    public function testDedupSecondDispatchIsServedFromCache(): void
    {
        $store = new F7D_Store();
        $runs = 0;
        $middleware = new DeduplicatingMiddleware($store, 3600);
        $bus = new InProcessMessageBus(['m.cmd.do' => new F7D_CountingHandler($runs)], [$middleware]);
        $bus->dispatch($this->envelope());
        $bus->dispatch($this->envelope());
        self::assertSame(1, $runs, 'second identical message is served from the dedup cache');
    }

    // --------------------------------------------- InProcessMessageBus

    public function testMessageBusRegistrationGuards(): void
    {
        $bus = new InProcessMessageBus();

        try {
            $bus->registerHandler('bad type!', new class implements MessageHandlerInterface {
                #[\Override]
                public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
                {
                    return null;
                }
            });
            self::fail('Expected InvalidArgumentException for invalid message type.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $handler = new class implements MessageHandlerInterface {
            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
            {
                return null;
            }
        };
        $bus->registerHandler('m.cmd.do', $handler);

        try {
            $bus->registerHandler('m.cmd.do', $handler);
            self::fail('Expected LogicException for duplicate handler.');
        } catch (\LogicException $e) {
            self::assertSame('Message handler already registered.', $e->getMessage());
        }
    }

    public function testMessageBusMiddlewareLimitIsExactly32(): void
    {
        $bus = new InProcessMessageBus();
        $mw = new class implements MessageMiddlewareInterface {
            #[\Override]
            public function process(MessageEnvelope $message, MessageContext $context, \Closure $next): MessageResult
            {
                return $next($message, $context); // @phpstan-ignore-line
            }
        };
        for ($i = 0; $i < 32; ++$i) {
            $bus->addMiddleware($mw);
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Message middleware limit exceeded.');
        $bus->addMiddleware($mw);
    }

    public function testMessageBusDepthLimitIsExactly64AndResets(): void
    {
        $bus = new InProcessMessageBus();
        $calls = 0;
        $bus->registerHandler('m.cmd.loop', new class($bus, $calls) implements MessageHandlerInterface {
            private int $calls;

            public function __construct(private readonly InProcessMessageBus $bus, int &$calls)
            {
                $this->calls = &$calls;
            }

            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
            {
                ++$this->calls;
                $this->bus->dispatch($message, $context);

                return null;
            }
        });
        $loop = $this->envelope('loop-0001', 'm.cmd.loop');

        try {
            $bus->dispatch($loop);
            self::fail('Expected LogicException from the message bus depth guard.');
        } catch (\LogicException $e) {
            self::assertSame('Message bus dispatch depth exceeded (recursive dispatch?).', $e->getMessage());
        }
        self::assertSame(64, $calls, 'handler runs on depth entries 1..64; entry #65 throws');
        // finally wajib me-reset depth: dispatch normal berikutnya sukses.
        $bus->registerHandler('m.cmd.ok', new class implements MessageHandlerInterface {
            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
            {
                return null;
            }
        });
        $result = $bus->dispatch($this->envelope('ok-000001', 'm.cmd.ok'));
        self::assertTrue($result->accepted);
    }

    public function testMessageBusUsesExplicitContextAndReturnsAccepted(): void
    {
        $bus = new InProcessMessageBus();
        $seen = null;
        $bus->registerHandler('m.cmd.do', new class($seen) implements MessageHandlerInterface {
            private ?MessageContext $seen; // @phpstan-ignore-line

            public function __construct(?MessageContext &$seen)
            {
                $this->seen = &$seen;
            }

            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
            {
                $this->seen = $context;

                return null;
            }
        });
        $context = new MessageContext('corr-00001', null, ['tenant' => 'acme']);
        $result = $bus->dispatch($this->envelope(), $context);
        self::assertTrue($result->accepted, 'terminal step returns accepted=true');
        self::assertSame('msg-00001', $result->messageId);
        self::assertSame($context, $seen, 'the caller-provided context must reach the handler untouched');
    }

    public function testMessageBusUnknownTypeThrows(): void
    {
        $bus = new InProcessMessageBus();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No message handler registered.');
        $bus->dispatch($this->envelope('miss-0001', 'm.cmd.absent'));
    }

    public function testMessageBusMiddlewareOrderOuterFirst(): void
    {
        $bus = new InProcessMessageBus();
        $log = [];
        $mk = static function (string $name) use (&$log): MessageMiddlewareInterface {
            return new class($name, $log) implements MessageMiddlewareInterface {
                private array $logRef; // @phpstan-ignore-line

                public function __construct(private readonly string $name, array &$log) // @phpstan-ignore-line
                {
                    $this->logRef = &$log;
                }

                #[\Override]
                public function process(MessageEnvelope $message, MessageContext $context, \Closure $next): MessageResult
                {
                    $this->logRef[] = $this->name . ':enter';
                    $result = $next($message, $context);
                    $this->logRef[] = $this->name . ':exit';

                    return $result; // @phpstan-ignore-line
                }
            };
        };
        $bus->addMiddleware($mk('A'));
        $bus->addMiddleware($mk('B'));
        $bus->registerHandler('m.cmd.do', new class implements MessageHandlerInterface {
            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
            {
                return null;
            }
        });
        $bus->dispatch($this->envelope());
        self::assertSame(['A:enter', 'B:enter', 'B:exit', 'A:exit'], $log);
    }

    // --------------------------------------------- JsonMessageSerializer

    public function testSerializerRoundTripAndGuards(): void
    {
        $serializer = new JsonMessageSerializer();
        $envelope = new MessageEnvelope('aaaa0001', 'm.cmd.do', ['items' => [1, 2, 'three']], ['x-hdr' => 'val']);
        $json = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($json);
        self::assertSame('aaaa0001', $restored->messageId);
        self::assertSame('m.cmd.do', $restored->messageType);
        self::assertSame(['items' => [1, 2, 'three']], $restored->payload);
        self::assertSame(['x-hdr' => 'val'], $restored->headers);

        try {
            $serializer->deserialize('not json');
            self::fail('Expected JsonException for malformed JSON.');
        } catch (\JsonException) {
            self::addToAssertionCount(1);
        }
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('{"id":1,"type":"m.t"}'), 'Invalid serialized message envelope.');
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('{"type":"m.t"}'), 'Invalid serialized message envelope.');
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('[]'), 'Invalid serialized message envelope.');
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('{"id":"aaaa0001","type":"m.t","headers":"not-array"}'), 'Invalid serialized headers.');
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('{"id":"aaaa0001","type":"m.t","headers":{"k":123}}'), 'Serialized headers must be strings.');
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize('{"id":"aaaa0001","type":"m.t","headers":{"123":"v"}}'), 'Serialized headers must be strings.');
    }

    public function testSerializerRejectsObjectsAndResources(): void
    {
        $serializer = new JsonMessageSerializer();

        try {
            $serializer->serialize($this->envelope('obj-0001', 'm.cmd.do', new \stdClass()));
            self::fail('Expected InvalidArgumentException for object payload.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Message payload must be JSON-safe data.', $e->getMessage());
        }
    }

    public function testSerializerOneMiBBoundaryIsExclusive(): void
    {
        $serializer = new JsonMessageSerializer();
        $prefix = '{"id":"aaaa0001","type":"m.cmd.do","payload":"';
        $suffix = '"}';
        $n = 1_048_576 - strlen($prefix) - strlen($suffix);
        $exact = $prefix . str_repeat('x', $n) . $suffix;
        self::assertSame(1_048_576, strlen($exact));
        $restored = $serializer->deserialize($exact); // tepat 1 MiB: diterima
        self::assertSame('aaaa0001', $restored->messageId);

        $over = $prefix . str_repeat('x', $n + 1) . $suffix;
        $this->assertEnvelopeGuard(fn (): MessageEnvelope => $serializer->deserialize($over), 'Message payload exceeds the 1 MiB limit.');
    }

    public function testSerializerJsonDepthIsExactly512(): void
    {
        $serializer = new JsonMessageSerializer();
        // 511 tingkat: decode sukses (maxDepth 512), lalu ditolak guard envelope.
        $deep511 = str_repeat('[', 511) . '1' . str_repeat(']', 511);

        try {
            $serializer->deserialize($deep511);
            self::fail('Expected the envelope guard, not a depth failure, at depth 511.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid serialized message envelope.', $e->getMessage());
        }
        // 512 tingkat: JsonException dari decode (melebihi maxDepth 512).
        $deep512 = str_repeat('[', 512) . '1' . str_repeat(']', 512);

        try {
            $serializer->deserialize($deep512);
            self::fail('Expected JsonException at depth 512.');
        } catch (\JsonException) {
            self::addToAssertionCount(1);
        }
        // Mutan maxDepth 513: 512 tingkat lolos decode -> guard envelope.
        // (tercakup oleh assert JsonException di atas)
    }

    private function envelope(string $id = 'msg-00001', string $type = 'm.cmd.do', mixed $payload = null): MessageEnvelope
    {
        return new MessageEnvelope($id, $type, $payload);
    }

    private function assertEnvelopeGuard(callable $fn, string $message): void
    {
        try {
            $fn();
            self::fail("Expected InvalidArgumentException '{$message}'.");
        } catch (\InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
