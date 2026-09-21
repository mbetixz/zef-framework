<?php

declare(strict_types=1);

/*
 * ZEF Framework — Mutation deep-dive #1 (v2.14.0): negative assertions and
 * boundary tests for the escaping clusters in Application/{Security,Cache,
 * Message,CQRS,Event}. Each test targets concrete escaped mutants from the
 * measured Infection baseline: default parameters, comparison boundaries,
 * validation throws, output-format invariants, middleware chain order and
 * idempotent-replay event semantics.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsHandlerConflictException;
use Zef\Framework\CQRS\CqrsHandlerNotFoundException;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\IdempotencyStoreInterface;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\InMemoryRateLimiter;

/**
 * @internal
 */
final class MutationDeepRestTest extends TestCase
{
    // -----------------------------------------------------------------
    // CsrfTokenManager
    // -----------------------------------------------------------------

    public function testCsrfConstructorBoundaryValidations(): void
    {
        $boundary = new CsrfTokenManager(str_repeat('b', 64), 16);
        self::assertSame(22, strlen(explode('.', $boundary->issue())[0]), '16 bytes base64url = 22 chars');

        try {
            new CsrfTokenManager(str_repeat('a', 31));
            self::fail('Secret below 32 bytes must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            new CsrfTokenManager(str_repeat('a', 32), 15);
            self::fail('tokenBytes below 16 must be rejected.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testCsrfIssuedTokenHasExactDefaultShape(): void
    {
        $manager = new CsrfTokenManager(str_repeat('s', 32));
        $token = $manager->issue();
        $parts = explode('.', $token);
        self::assertCount(2, $parts);
        // base64url of exactly 32 random bytes (padding stripped) is 43 chars.
        self::assertSame(43, strlen($parts[0]));
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]+$/', $parts[0]));
        self::assertSame(64, strlen($parts[1]));
        self::assertSame(1, preg_match('/^[a-f0-9]{64}$/', $parts[1]));
    }

    public function testCsrfIsValidMatrix(): void
    {
        $manager = new CsrfTokenManager(str_repeat('k', 32));
        $valid = $manager->issue();
        self::assertTrue($manager->isValid($valid));

        [$value, $signature] = explode('.', $valid);
        self::assertFalse($manager->isValid(''));
        self::assertFalse($manager->isValid($value));
        self::assertFalse($manager->isValid($value . '.'));
        self::assertFalse($manager->isValid('.' . $signature));
        self::assertFalse($manager->isValid($value . 'x.' . $signature), 'tampered value must fail');
        self::assertFalse($manager->isValid($value . '.' . str_repeat('0', 64)), 'wrong mac must fail');
        self::assertFalse($manager->isValid($value . '.' . str_repeat('Z', 64)), 'non-hex mac must fail');
        self::assertFalse($manager->isValid($value . '.' . $signature . '.x'), 'trailing junk must fail');
        $other = new CsrfTokenManager(str_repeat('q', 32));
        self::assertFalse($other->isValid($valid), 'token bound to issuing secret');
    }

    // -----------------------------------------------------------------
    // InMemoryRateLimiter
    // -----------------------------------------------------------------

    public function testRateLimiterValidatesInputsAtBoundaries(): void
    {
        new InMemoryRateLimiter(1);

        try {
            new InMemoryRateLimiter(0);
            self::fail('maxKeys 0 must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $limiter = new InMemoryRateLimiter();

        try {
            $limiter->check('', 1, 60);
            self::fail('Empty key must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $limiter->check('key-one', 0, 60);
            self::fail('limit 0 must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $limiter->check('key-one', 1, 0);
            self::fail('windowSeconds 0 must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $decision = $limiter->check('key-one', 1, 1);
        self::assertTrue($decision->allowed);
    }

    public function testRateLimiterRepeatKeyDoesNotHitCapacityGuard(): void
    {
        $limiter = new InMemoryRateLimiter(1);
        $first = $limiter->check('same-key', 1, 60);
        self::assertTrue($first->allowed);
        self::assertSame(0, $first->remaining);
        $second = $limiter->check('same-key', 1, 60);
        self::assertFalse($second->allowed, 'limit exhausted for repeated key');
        self::assertSame(0, $second->remaining);
        self::assertSame(60, $second->retryAfter);
    }

    public function testRateLimiterCapacityExhaustionThrows(): void
    {
        $limiter = new InMemoryRateLimiter(2);
        self::assertTrue($limiter->check('alpha', 1, 60)->allowed);
        self::assertTrue($limiter->check('beta', 1, 60)->allowed);

        try {
            $limiter->check('gamma', 1, 60);
            self::fail('Capacity exhaustion must surface as RuntimeException.');
        } catch (\RuntimeException) {
        }
    }

    public function testRateLimiterDefaultCapacityIsExactlyTenThousand(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 10000; ++$i) {
            $decision = $limiter->check('key-' . $i, 1, 3600);
            self::assertTrue($decision->allowed);
        }

        try {
            $limiter->check('key-overflow', 1, 3600);
            self::fail('Default capacity is 10000 keys.');
        } catch (\RuntimeException) {
        }
    }

    public function testRateLimiterSweepsExpiredBucketsForCapacity(): void
    {
        $limiter = new InMemoryRateLimiter(1);
        $limiter->check('ephemeral', 1, 1);
        sleep(2);
        $decision = $limiter->check('fresh', 1, 1);
        self::assertTrue($decision->allowed, 'expired bucket must be swept before capacity check');
    }

    // -----------------------------------------------------------------
    // InMemoryLockStore
    // -----------------------------------------------------------------

    public function testLockStoreValidatesKeyOwnerAndTtlBounds(): void
    {
        $store = new InMemoryLockStore();
        $key = str_repeat('k', 256);
        $owner = str_repeat('o', 256);

        $store->acquire($key, $owner, 86400);
        $store->acquire('k', 'o', 1);
        self::assertTrue($store->acquire('k2', 'o2', 1));
        self::assertSame('o2', $store->holder('k2'), 'boundary TTL values are accepted');

        foreach (
            [
                ['', 'o', 10],
                [str_repeat('k', 257), 'o', 10],
                ['k', '', 10],
                ['k', str_repeat('o', 257), 10],
                ['k', 'o', 0],
                ['k', 'o', 86401],
            ] as [$badKey, $badOwner, $badTtl]
        ) {
            try {
                $store->acquire($badKey, $badOwner, $badTtl);
                self::fail("acquire('$badKey', '$badOwner', $badTtl) must be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testLockStoreExclusiveReentryAndRelease(): void
    {
        $store = new InMemoryLockStore();
        self::assertTrue($store->acquire('res', 'owner-1', 10));
        self::assertFalse($store->acquire('res', 'owner-2', 10));
        self::assertTrue($store->acquire('res', 'owner-1', 10), 're-entrant acquire by same owner');
        self::assertSame('owner-1', $store->holder('res'));
        self::assertFalse($store->release('res', 'owner-2'));
        self::assertTrue($store->release('res', 'owner-1'));
        self::assertNull($store->holder('res'));
        self::assertFalse($store->release('res', 'owner-1'), 'double release is a no-op returning false');
    }

    public function testLockStoreExpiryAndRefreshWithInjectedClock(): void
    {
        $now = 1000;
        $store = new InMemoryLockStore(static function () use (&$now): int {
            return $now;
        });

        self::assertTrue($store->acquire('job', 'worker-a', 5));
        self::assertSame('worker-a', $store->holder('job'));

        $now = 1004;
        self::assertTrue($store->refresh('job', 'worker-a', 5), 'refresh inside lease window extends expiry');
        self::assertSame('worker-a', $store->holder('job'));

        $now = 1006;
        self::assertFalse($store->refresh('job', 'worker-b', 5), 'foreign owner cannot refresh a live lease');

        $now = 1008;
        self::assertTrue($store->refresh('job', 'worker-a', 5), 'lease (expiry 1009) still active at 1008');

        $now = 1014;
        self::assertFalse($store->refresh('job', 'worker-a', 5), 'expired lease cannot be refreshed');
        self::assertNull($store->holder('job'));
        self::assertTrue($store->acquire('job', 'worker-b', 5), 'expired lease is reclaimable');
        $now = 1020;
        self::assertFalse($store->refresh('job', 'worker-a', 5), 'stale owner after expiry');
    }

    // -----------------------------------------------------------------
    // JsonMessageSerializer
    // -----------------------------------------------------------------

    public function testSerializerRejectsNonJsonSafePayloads(): void
    {
        $serializer = new JsonMessageSerializer();

        foreach (
            [
                new \stdClass(),
                ['nested' => ['deep' => new \stdClass()]],
                fopen('php://memory', 'rb'),
            ] as $badPayload
        ) {
            try {
                $serializer->serialize(new MessageEnvelope('msg-0001', 'app.rejected', $badPayload));
                self::fail('Non JSON-safe payload must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $ok = $serializer->serialize(new MessageEnvelope('msg-0001', 'app.rejected', null));
        $decoded = json_decode($ok, true);
        self::assertIsArray($decoded);
        self::assertSame('null', $decoded['payload'] ?? 'null');
    }

    public function testSerializerRoundTripPreservesEnvelope(): void
    {
        $serializer = new JsonMessageSerializer();
        $envelope = new MessageEnvelope('msg-0042', 'app.order.placed', ['sku' => 'ZEF-1', 'qty' => 2], ['trace.id' => 'abc']);

        $json = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($json);

        self::assertSame($envelope->messageId, $restored->messageId);
        self::assertSame($envelope->messageType, $restored->messageType);
        self::assertSame($envelope->payload, $restored->payload);
        self::assertSame($envelope->headers, $restored->headers);
    }

    public function testSerializerDeserializationValidationMatrix(): void
    {
        $serializer = new JsonMessageSerializer();

        try {
            $serializer->deserialize(str_repeat('x', 1048577));
            self::fail('Oversized payload must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $serializer->deserialize('{not-json');
            self::fail('Malformed JSON must raise JsonException.');
        } catch (\JsonException) {
        }

        foreach (
            [
                'null',
                '[]',
                '{"id":1,"type":"app.x"}',
                '{"id":"msg-0001","type":5}',
                '{"id":"msg-0001","type":"app.x","headers":"nope"}',
                '{"id":"msg-0001","type":"app.x","headers":{"a":1}}',
            ] as $bad
        ) {
            try {
                $serializer->deserialize($bad);
                self::fail("Envelope '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }

        $missingPayload = $serializer->deserialize('{"id":"msg-0002","type":"app.ping"}');
        self::assertNull($missingPayload->payload);
        self::assertSame([], $missingPayload->headers);
    }

    // -----------------------------------------------------------------
    // CommandBus + CqrsBusTrait
    // -----------------------------------------------------------------

    public function testCommandBusValidatesConfigurationAndRegistration(): void
    {
        try {
            new CommandBus(idempotencyTtlSeconds: 0);
            self::fail('Non-positive idempotency TTL must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $bus = new CommandBus();

        try {
            $bus->register('', static fn (): int => 1);
            self::fail('Empty command class must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $bus->register('Zef\Nope\MissingCommand', static fn (): int => 1);
            self::fail('Unknown command class must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $bus->register(MutationDeepRestCommand::class, static fn (): string => 'ok');

        try {
            $bus->register(MutationDeepRestCommand::class, static fn (): string => 'dup');
            self::fail('Duplicate registration must conflict.');
        } catch (CqrsHandlerConflictException) {
        }

        $bus->freeze();
        self::assertTrue($bus->isFrozen());

        try {
            $bus->register(MutationDeepRestOtherCommand::class, static fn (): string => 'x');
            self::fail('Registration on frozen bus must fail.');
        } catch (\LogicException) {
        }

        try {
            $bus->use(new MutationDeepRestNoopMiddleware());
            self::fail('Middleware on frozen bus must fail.');
        } catch (\LogicException) {
        }
    }

    public function testCommandBusResolvesHandlersByExactClassInterfaceAndReportsConflicts(): void
    {
        $bus = new CommandBus();
        $bus->register(MutationDeepRestCommandInterface::class, static fn (): string => 'via-interface');

        self::assertSame('via-interface', $bus->dispatch(new MutationDeepRestCommand()));

        $bus->register(MutationDeepRestCommand::class, static fn (): string => 'exact');
        self::assertSame('exact', $bus->dispatch(new MutationDeepRestCommand()), 'exact registration shadows the interface one');

        $bus->register(MutationDeepRestBaseCommand::class, static fn (): string => 'base');

        try {
            $bus->dispatch(new MutationDeepRestSiblingCommand());
            self::fail('Interface + parent matches must be ambiguous.');
        } catch (CqrsHandlerConflictException) {
        }

        try {
            $bus->dispatch(new MutationDeepRestOtherCommand());
            self::fail('Unregistered command must not resolve.');
        } catch (CqrsHandlerNotFoundException) {
        }
    }

    public function testCommandBusAcceptsHandlerObjectsAndClosures(): void
    {
        $objectBus = new CommandBus();
        $objectBus->register(MutationDeepRestCommand::class, new MutationDeepRestInvokableHandler());
        self::assertSame('invoked-handler', $objectBus->dispatch(new MutationDeepRestCommand()));

        $closureBus = new CommandBus();
        $closureBus->register(MutationDeepRestCommand::class, static fn (): string => 'closure-handler');
        self::assertSame('closure-handler', $closureBus->dispatch(new MutationDeepRestCommand()));
    }

    public function testCommandBusMiddlewareRunsOutermostFirst(): void
    {
        $trace = new \ArrayObject();
        $outer = new MutationDeepRestTraceMiddleware('A', $trace);
        $inner = new MutationDeepRestTraceMiddleware('B', $trace);
        $bus = new CommandBus();
        $bus->use($outer);
        $bus->use($inner);
        $bus->register(MutationDeepRestCommand::class, static function () use ($inner): string {
            $inner->mark('handler');

            return 'done';
        });

        self::assertSame('done', $bus->dispatch(new MutationDeepRestCommand()));
        self::assertSame(['A>', 'B>', 'handler', '<B', '<A'], $trace->getArrayCopy(), 'outer middleware wraps inner on both sides');
    }

    public function testCommandBusIdempotentReplayCachesResultAndSkipsEventRefire(): void
    {
        $store = new MutationDeepRestCachingStore();
        $eventBus = new MutationDeepRestRecordingEventBus();
        $bus = new CommandBus($store, 900, $eventBus);
        $bus->register(MutationDeepRestCommand::class, static fn (): CqrsEventResult => new CqrsEventResult('first-run', [new \stdClass()]));

        $context = CqrsContext::create(idempotencyKey: 'idem-key-1');
        self::assertSame('first-run', $bus->dispatch(new MutationDeepRestCommand(), $context));
        self::assertSame('first-run', $bus->dispatch(new MutationDeepRestCommand(), $context), 'replay returns cached result');

        self::assertSame(1, $store->producerRuns, 'producer must run exactly once');
        self::assertCount(1, $eventBus->dispatched, 'events must not re-fire on replay');
        $expectedKey = hash('sha256', MutationDeepRestCommand::class . '|idem-key-1');
        self::assertSame([$expectedKey, $expectedKey], $store->keys, 'every dispatch consults the store');
        self::assertSame([900, 900], $store->ttls);
    }

    public function testCommandBusFanOutDeliversEventsWithContext(): void
    {
        $eventBus = new MutationDeepRestRecordingEventBus();
        $bus = new CommandBus(null, 3600, $eventBus);
        $bus->register(MutationDeepRestCommand::class, static fn (): CqrsEventResult => new CqrsEventResult(null, [new \stdClass(), new \stdClass()]));

        $bus->dispatch(new MutationDeepRestCommand());
        self::assertCount(2, $eventBus->dispatched);
        self::assertCount(2, $eventBus->contexts);
    }

    // -----------------------------------------------------------------
    // EventDispatcher
    // -----------------------------------------------------------------

    public function testEventDispatcherDefaultPriorityIsZeroAndValidationHolds(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(\stdClass::class, static function (object $event): void {});
        $registrations = $dispatcher->registrations();
        self::assertCount(1, $registrations);
        self::assertInstanceOf(EventRegistration::class, $registrations[0]);
        self::assertSame(0, $registrations[0]->priority);

        $interfaceBus = new EventDispatcher();
        $interfaceBus->listen(\Stringable::class, static function (object $event): void {});
        self::assertCount(1, $interfaceBus->registrations(), 'interface event classes are registrable');

        foreach (['', 'Zef\Nope\MissingEvent'] as $badClass) {
            try {
                $interfaceBus->listen($badClass, static function (object $event): void {});
                self::fail("Event class '{$badClass}' must be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }
    }
}

/**
 * @internal
 */
final class MutationDeepRestOtherCommand {}

/**
 * @internal
 */
abstract class MutationDeepRestBaseCommand {}

/**
 * @internal
 */
interface MutationDeepRestCommandInterface {}

/**
 * @internal
 */
final class MutationDeepRestCommand extends MutationDeepRestBaseCommand implements MutationDeepRestCommandInterface {}

/**
 * @internal
 */
final class MutationDeepRestSiblingCommand extends MutationDeepRestBaseCommand implements MutationDeepRestCommandInterface {}

/**
 * @internal
 */
final class MutationDeepRestInvokableHandler implements CommandHandlerInterface
{
    #[\Override]
    public function __invoke(object $command, CqrsContext $context): string
    {
        return 'invoked-handler';
    }
}

/**
 * @internal
 */
final class MutationDeepRestNoopMiddleware implements CqrsMiddlewareInterface
{
    #[\Override]
    public function process(object $message, CqrsContext $context, \Closure $next): mixed
    {
        return $next($message, $context);
    }
}

/**
 * @internal
 */
final class MutationDeepRestTraceMiddleware implements CqrsMiddlewareInterface
{
    /**
     * @param \ArrayObject<int, string> $sink
     */
    public function __construct(
        private readonly string $label,
        private readonly \ArrayObject $sink,
    ) {}

    #[\Override]
    public function process(object $message, CqrsContext $context, \Closure $next): mixed
    {
        $this->sink->append($this->label . '>');
        $result = $next($message, $context);
        $this->sink->append('<' . $this->label);

        return $result;
    }

    /** Records an entry inside the shared trace, e.g. from the wrapped handler. */
    public function mark(string $entry): void
    {
        $this->sink->append($entry);
    }
}

/**
 * @internal
 */
final class MutationDeepRestCachingStore implements IdempotencyStoreInterface
{
    /** @var array<string,mixed> */
    public array $cache = [];

    /** @var list<string> */
    public array $keys = [];

    /** @var list<int> */
    public array $ttls = [];

    public int $producerRuns = 0;

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        $this->keys[] = $key;
        $this->ttls[] = $ttlSeconds;
        if (!array_key_exists($key, $this->cache)) {
            ++$this->producerRuns;
            $this->cache[$key] = $producer();
        }

        return $this->cache[$key];
    }
}

/**
 * @internal
 */
final class MutationDeepRestRecordingEventBus implements EventBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    /** @var list<EventContext> */
    public array $contexts = [];

    #[\Override]
    public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

    #[\Override]
    public function subscribe(EventSubscriberInterface $subscriber): void {}

    #[\Override]
    public function dispatchWithContext(object $event, EventContext $context): object
    {
        $this->dispatched[] = $event;
        $this->contexts[] = $context;

        return $event;
    }

    #[\Override]
    public function dispatch(object $event): object
    {
        return $this->dispatchWithContext($event, new EventContext(
            eventId: 'evt-deep-rest-0001',
            occurredAtUnixNano: 0,
            correlationId: 'corr-deep-1',
        ));
    }

    #[\Override]
    public function registrations(): array
    {
        return [];
    }

    #[\Override]
    public function freeze(): void {}
}
