<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\EventUpcaster;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\RowCast;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;
use Zef\Framework\EventSourcing\UpcasterInterface;

/**
 * v2.23.0 Event Sourcing Hardening: upcasting — the EventUpcaster registry
 * chains UpcasterInterface implementations per event type during replay so
 * legacy streams load against current aggregate code unchanged on disk.
 *
 * @internal
 */
final class EventSourcingUpcastTest extends TestCase
{
    public function testRegistryPassesThroughUnregisteredTypes(): void
    {
        $registry = new EventUpcaster();
        $event = $this->stored('e.v2', 1, 1, ['value' => 7]);

        self::assertSame($event, $registry->transform($event), 'no upcaster means zero-cost pass-through of the same instance');
        self::assertSame([], $registry->registeredTypes());
    }

    public function testUpcasterReshapesLegacyPayload(): void
    {
        $registry = new EventUpcaster($this->renameAndReshapeUpcaster());
        $legacy = $this->stored('e.legacy', 1, 1, ['old_value' => 7]);

        $upcast = $registry->transform($legacy);
        self::assertSame('e.v2', $upcast->eventType);
        self::assertSame(['value' => 7], $upcast->payload);
        self::assertSame($legacy->eventId, $upcast->eventId, 'identity fields are frozen');
        self::assertSame($legacy->version, $upcast->version);
        self::assertSame($legacy->globalSequence, $upcast->globalSequence);
    }

    public function testUpcasterChainRunsInRegistrationOrder(): void
    {
        $first = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.legacy'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild($event, 'e.mid', ['step' => 1]);
            }
        };
        $second = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.mid'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild($event, 'e.v2', ['step' => 1 + RowCast::int($event->payload['step'] ?? null)]);
            }
        };

        $registry = new EventUpcaster($first, $second);
        $upcast = $registry->transform($this->stored('e.legacy', 1, 1));
        self::assertSame('e.v2', $upcast->eventType, 'the legacy event walked the full v1→v2→v3 path');
        self::assertSame(['step' => 2], $upcast->payload, 'the second upcaster consumed the first one\'s output');
    }

    public function testRegistryRejectsUpcasterWithoutTypes(): void
    {
        $empty = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return [];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return $event;
            }
        };

        try {
            new EventUpcaster($empty);
            self::fail('an upcaster without event types is dead weight — reject it');
        } catch (EventSourcingException $e) {
            self::assertSame('Upcaster ' . $empty::class . ' declares no event types.', $e->getMessage());
        }
    }

    public function testRegistryValidatesEventTypeGrammar(): void
    {
        $wild = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['not grammar compliant!'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return $event;
            }
        };

        try {
            new EventUpcaster($wild);
            self::fail('upcaster event types follow the event grammar');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('upcaster event type', $e->getMessage());
        }
    }

    public function testRegistryRejectsIdentityMutation(): void
    {
        $thief = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.legacy'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild($event, 'e.legacy', [], version: $event->version + 1);
            }
        };
        $registry = new EventUpcaster($thief);

        try {
            $registry->transform($this->stored('e.legacy', 1, 1));
            self::fail('an upcaster must never mutate stream identity');
        } catch (EventSourcingException $e) {
            self::assertSame(
                'Upcaster ' . $thief::class . ' mutated the stream identity of event '
                . str_pad('1', 32, '0', \STR_PAD_LEFT) . '.',
                $e->getMessage(),
            );
        }
    }

    /**
     * The identity guard checks every frozen coordinate — mutating ANY single
     * identity field (not just the version) must be rejected, so a partial
     * guard (logical-AND swap) cannot slip through.
     */
    public function testIdentityGuardRejectsEachFrozenFieldIndividually(): void
    {
        $mutations = [
            'eventId' => static fn (StoredEvent $e): StoredEvent => UpcastTestRebuilder::rebuild($e, 'e.legacy', eventId: 'f' . substr($e->eventId, 1)),
            'aggregateType' => static fn (StoredEvent $e): StoredEvent => UpcastTestRebuilder::rebuild($e, 'e.legacy', aggregateType: 'test.other'),
            'aggregateId' => static fn (StoredEvent $e): StoredEvent => UpcastTestRebuilder::rebuild($e, 'e.legacy', aggregateId: 'a-2'),
            'globalSequence' => static fn (StoredEvent $e): StoredEvent => UpcastTestRebuilder::rebuild($e, 'e.legacy', globalSequence: $e->globalSequence + 1000),
            'recordedAtUnixNano' => static fn (StoredEvent $e): StoredEvent => UpcastTestRebuilder::rebuild($e, 'e.legacy', recordedAtUnixNano: $e->recordedAtUnixNano + 1000),
        ];

        foreach ($mutations as $field => $mutate) {
            $thief = new readonly class($mutate) implements UpcasterInterface {
                public function __construct(
                    /** @var \Closure(StoredEvent): StoredEvent */
                    private \Closure $mutate,
                ) {}

                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.legacy'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return ($this->mutate)($event);
                }
            };
            $registry = new EventUpcaster($thief);

            try {
                $registry->transform($this->stored('e.legacy', 1, 1, [], recordedAt: 1_700_000_000_000_000_000));
                self::fail("mutating the frozen field '{$field}' must be rejected");
            } catch (EventSourcingException $e) {
                self::assertSame(
                    'Upcaster ' . $thief::class . ' mutated the stream identity of event '
                    . str_pad('1', 32, '0', \STR_PAD_LEFT) . '.',
                    $e->getMessage(),
                    "the guard must catch a '{$field}' mutation",
                );
            }
        }
    }

    /**
     * A LEGAL rename chain of exactly MAX_HOPS steps must complete — the
     * hop budget is a corruption guard, not a limit on legitimate migrations.
     */
    public function testUpcasterChainOfExactlyMaxHopsSucceeds(): void
    {
        $upcasters = [];
        foreach (range(1, EventUpcaster::MAX_HOPS) as $step) {
            $from = 'hop.' . $step;
            $to = 'hop.' . ($step + 1);
            $upcasters[] = new readonly class($from, $to) implements UpcasterInterface {
                public function __construct(private string $from, private string $to) {}

                #[\Override]
                public function eventTypes(): array
                {
                    return [$this->from];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return UpcastTestRebuilder::rebuild($event, $this->to);
                }
            };
        }
        $registry = new EventUpcaster(...$upcasters);

        $upcast = $registry->transform($this->stored('hop.1', 1, 1));
        self::assertSame('hop.' . (EventUpcaster::MAX_HOPS + 1), $upcast->eventType, 'a chain of exactly MAX_HOPS legal renames completes');
    }

    /**
     * A legal chain of MAX_HOPS + 1 steps is rejected even without a cycle —
     * the budget is a hard ceiling, and the message names the exact hop.
     */
    public function testUpcasterChainBeyondMaxHopsRejectedWithExactHop(): void
    {
        $upcasters = [];
        foreach (range(1, EventUpcaster::MAX_HOPS + 1) as $step) {
            $from = 'hop.' . $step;
            $to = 'hop.' . ($step + 1);
            $upcasters[] = new readonly class($from, $to) implements UpcasterInterface {
                public function __construct(private string $from, private string $to) {}

                #[\Override]
                public function eventTypes(): array
                {
                    return [$this->from];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return UpcastTestRebuilder::rebuild($event, $this->to);
                }
            };
        }
        $registry = new EventUpcaster(...$upcasters);

        try {
            $registry->transform($this->stored('hop.1', 1, 1));
            self::fail('a chain deeper than MAX_HOPS must be rejected');
        } catch (EventSourcingException $e) {
            self::assertSame(
                'Upcaster chain exceeded ' . EventUpcaster::MAX_HOPS . " hops at 'hop." . (EventUpcaster::MAX_HOPS + 1) . "' (rename cycle?).",
                $e->getMessage(),
            );
        }
    }

    public function testRegisteredTypesKeepsFirstRegisteredOrder(): void
    {
        $multi = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.zulu', 'e.alpha'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return $event;
            }
        };
        $other = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.mike'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return $event;
            }
        };

        $registry = new EventUpcaster($multi, $other);
        self::assertSame(['e.zulu', 'e.alpha', 'e.mike'], $registry->registeredTypes());
    }

    public function testRepositoryReplaysLegacyStreamThroughUpcaster(): void
    {
        $store = new InMemoryEventStore();
        // A stream written when the event was named e.legacy with old_value.
        $store->appendToStream('test.upcast', 'a-1', 0, new PendingEvent('e.legacy', ['old_value' => 5]));
        $store->appendToStream('test.upcast', 'a-1', 1, new PendingEvent('e.legacy', ['old_value' => 7]));

        $repository = new AggregateRepository(store: $store, upcasters: new EventUpcaster($this->renameAndReshapeUpcaster()));
        $aggregate = $repository->findOrFail(UpcastTestAggregate::class, 'a-1');

        self::assertSame(12, $aggregate->value(), 'both legacy events arrived as e.v2 and mutated state');
        self::assertSame(['e.v2', 'e.v2'], $aggregate->seen());
        self::assertSame(2, $aggregate->version());
    }

    public function testRepositoryWithoutUpcasterReplaysRawEvents(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream('test.upcast', 'a-1', 0, new PendingEvent('e.v2', ['value' => 3]));

        $repository = new AggregateRepository(store: $store);
        $aggregate = $repository->findOrFail(UpcastTestAggregate::class, 'a-1');

        self::assertSame(['e.v2'], $aggregate->seen(), 'no registry means raw events hit apply() untouched');
        self::assertSame(3, $aggregate->value());
    }

    public function testUpcastAppliesToSnapshotReplayTail(): void
    {
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $policy = SnapshotPolicy::every(1);

        // v1 is written in the CURRENT shape (e.v2) and gets snapshotted.
        $repository = new AggregateRepository(store: $store, snapshots: $snapshots, policy: $policy);
        $live = UpcastTestAggregate::start('a-1', 5);
        $repository->persist($live);

        // v2 arrives later in the LEGACY shape (as if written by old code).
        $store->appendToStream('test.upcast', 'a-1', 1, new PendingEvent('e.legacy', ['old_value' => 7]));

        $hardened = new AggregateRepository(store: $store, snapshots: $snapshots, policy: $policy, upcasters: new EventUpcaster($this->renameAndReshapeUpcaster()));
        $aggregate = $hardened->findOrFail(UpcastTestAggregate::class, 'a-1');

        self::assertSame(12, $aggregate->value(), 'snapshot value 5 + upcasted legacy tail 7');
        self::assertSame(['e.v2'], $aggregate->seen(), 'only the tail after the snapshot replays — and it was upcast');
        self::assertSame(2, $aggregate->version());
    }

    public function testRegistryRejectsRenameCycle(): void
    {
        $aToB = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.aaa'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild($event, 'e.bbb');
            }
        };
        $bToA = new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.bbb'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild($event, 'e.aaa');
            }
        };
        $registry = new EventUpcaster($aToB, $bToA);

        try {
            $registry->transform($this->stored('e.aaa', 1, 1));
            self::fail('a rename cycle must not loop forever');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('rename cycle', $e->getMessage());
        }
    }

    private function renameAndReshapeUpcaster(): UpcasterInterface
    {
        return new class implements UpcasterInterface {
            #[\Override]
            public function eventTypes(): array
            {
                return ['e.legacy'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                return UpcastTestRebuilder::rebuild(
                    $event,
                    'e.v2',
                    ['value' => $event->payload['old_value'] ?? 0],
                    $event->metadata,
                );
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stored(string $type, int $version, int $sequence, array $payload = [], ?int $recordedAt = null): StoredEvent
    {
        return new StoredEvent(
            eventId: str_pad((string) $version, 32, '0', \STR_PAD_LEFT),
            aggregateType: 'test.upcast',
            aggregateId: 'a-1',
            version: $version,
            globalSequence: $sequence,
            eventType: $type,
            payload: $payload,
            recordedAtUnixNano: $recordedAt ?? 1_700_000_000_000_000_000,
        );
    }
}

/**
 * Construction helper for the upcasting tests — a private static method
 * inside an anonymous upcaster cannot be imported by the registry test,
 * so the rebuild primitive lives in a tiny dedicated class.
 *
 * @internal
 */
final class UpcastTestRebuilder
{
    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public static function rebuild(
        StoredEvent $event,
        string $newType,
        array $payload = [],
        array $metadata = [],
        ?int $version = null,
        ?string $eventId = null,
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?int $globalSequence = null,
        ?int $recordedAtUnixNano = null,
    ): StoredEvent {
        return new StoredEvent(
            eventId: $eventId ?? $event->eventId,
            aggregateType: $aggregateType ?? $event->aggregateType,
            aggregateId: $aggregateId ?? $event->aggregateId,
            version: $version ?? $event->version,
            globalSequence: $globalSequence ?? $event->globalSequence,
            eventType: $newType,
            payload: $payload,
            metadata: $metadata,
            recordedAtUnixNano: $recordedAtUnixNano ?? $event->recordedAtUnixNano,
        );
    }
}
