<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\EventSourcing\AggregateNotFoundException;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRecorder;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\Projector;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.19.0 — Event Sourcing Application services: AggregateRepository
 * (load/persist/snapshot/outbox), SnapshotPolicy, Projector checkpoints,
 * OutboxRecorder enrichment, OutboxRelay retry/backoff/dead-letter.
 *
 * @internal
 */
require_once __DIR__ . '/EventSourcingTestAccount.php';

/**
 * @internal
 */
final class EventSourcingServicesTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    // -------------------------------------------------- SnapshotPolicy

    public function testSnapshotPolicy(): void
    {
        $policy = SnapshotPolicy::every(10);
        self::assertSame(10, $policy->interval());
        self::assertFalse($policy->shouldSnapshot(0));
        self::assertTrue($policy->shouldSnapshot(10));
        self::assertTrue($policy->shouldSnapshot(100));
        self::assertFalse($policy->shouldSnapshot(11));
        self::assertFalse($policy->shouldSnapshot(9));

        self::assertSame(SnapshotPolicy::DEFAULT_INTERVAL, SnapshotPolicy::default()->interval());

        try {
            SnapshotPolicy::every(0);
            self::fail('interval 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Snapshot interval must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            SnapshotPolicy::every(-5);
            self::fail('negative interval must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Snapshot interval must be >= 1 (got -5).', $e->getMessage());
        }
    }

    // -------------------------------------------------- AggregateRepository (in-memory)

    public function testRepositoryPersistLoadRoundTrip(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $repo = new AggregateRepository($store);

        $aggregate = EventSourcingTestAccount::open('acc-1', 100);
        $aggregate->deposit(25);
        $stored = $repo->persist($aggregate);
        self::assertCount(2, $stored);
        self::assertSame('account.opened', $stored[0]->eventType);
        self::assertSame('test.account', $stored[0]->aggregateType);
        self::assertFalse($aggregate->hasPendingEvents());

        // Persisting again with nothing pending is a no-op.
        self::assertSame([], $repo->persist($aggregate));

        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(125, $loaded->balance());
        self::assertSame(2, $loaded->version());

        self::assertNull($repo->find(EventSourcingTestAccount::class, 'acc-missing'));

        try {
            $repo->findOrFail(EventSourcingTestAccount::class, 'acc-missing');
            self::fail('findOrFail must throw');
        } catch (AggregateNotFoundException $e) {
            self::assertSame('acc-missing', $e->aggregateId());
        }
    }

    public function testRepositoryRejectsNonAggregateClass(): void
    {
        $repo = new AggregateRepository(new InMemoryEventStore());

        try {
            $repo->find(self::class, 'x'); // @phpstan-ignore-line intentional non-aggregate input
            self::fail('non-aggregate class must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('is not an AggregateRoot subclass', $e->getMessage());
        }
    }

    public function testRepositoryRejectsBadAggregateIdGrammar(): void
    {
        $repo = new AggregateRepository(new InMemoryEventStore());

        try {
            $repo->find(EventSourcingTestAccount::class, "bad\nid");
            self::fail('bad id grammar must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    public function testRepositorySnapshotWorkflow(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $snapshots = new InMemorySnapshotStore();
        $clockTick = 0;
        $repo = new AggregateRepository(
            store: $store,
            snapshots: $snapshots,
            policy: SnapshotPolicy::every(2),
            clock: static function () use (&$clockTick): int {
                ++$clockTick;

                return self::NANO + $clockTick;
            },
        );

        $aggregate = EventSourcingTestAccount::open('acc-1', 1);
        $aggregate->deposit(1); // version 2 → snapshot at 2
        $repo->persist($aggregate);
        self::assertSame(1, $snapshots->count());
        $snapshot = $snapshots->load('test.account', 'acc-1');
        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->version);
        self::assertSame(self::NANO + 1, $snapshot->createdAtUnixNano, 'injectable clock feeds createdAt');
        self::assertSame(['id' => 'acc-1', 'balance' => 2, 'log' => ['account.opened', 'account.deposited']], $snapshot->state);

        // version 3 → no snapshot
        $aggregate->deposit(1);
        $repo->persist($aggregate);
        self::assertSame(1, $snapshots->count());

        // Load: restored from snapshot then replayed event 3.
        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(3, $loaded->balance());
        self::assertSame(3, $loaded->version());

        // Snapshot for another aggregate identity is ignored.
        $snapshots->save(new Snapshot('test.account', 'other', 99, ['id' => 'other', 'balance' => 1, 'log' => []]));
        $fresh = EventSourcingTestAccount::open('acc-2', 7);
        $repo->persist($fresh);
        $loadedFresh = $repo->findOrFail(EventSourcingTestAccount::class, 'acc-2');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loadedFresh);
        self::assertSame(7, $loadedFresh->balance());
    }

    public function testRepositoryPolicyWithoutSnapshotsIsRejected(): void
    {
        try {
            new AggregateRepository(new InMemoryEventStore(), null, SnapshotPolicy::every(5));
            self::fail('policy without snapshot store must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('SnapshotPolicy provided without a SnapshotStore.', $e->getMessage());
        }
        // Default policy with no snapshot store is fine.
        new AggregateRepository(new InMemoryEventStore());
        self::addToAssertionCount(1);
    }

    public function testRepositoryWithOutboxRecordsEnrichedEntries(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO + 1);
        $repo = new AggregateRepository($store, outbox: new OutboxRecorder($outbox));

        $aggregate = EventSourcingTestAccount::open('acc-1', 5);
        $aggregate->deposit(5, metadata: ['tenant' => 't1']);
        $stored = $repo->persist($aggregate);
        self::assertSame(2, $outbox->countPending());

        // (createdAt, id) ordering — both entries share one clock tick, so
        // match by message type instead of assuming FIFO position.
        $entries = $outbox->due(10, PHP_INT_MAX);
        $byType = [];
        foreach ($entries as $entry) {
            $byType[$entry->messageType] = $entry;
        }
        $types = array_keys($byType);
        sort($types);
        self::assertSame(['account.deposited', 'account.opened'], $types);
        self::assertSame(['initial' => 5], $byType['account.opened']->payload);
        $depositedMeta = $byType['account.deposited']->metadata;
        self::assertSame('t1', $depositedMeta['tenant'], 'user metadata survives');
        self::assertSame($stored[1]->eventId, $depositedMeta['eventId']);
        self::assertSame('test.account', $depositedMeta['aggregateType']);
        self::assertSame('acc-1', $depositedMeta['aggregateId']);
        self::assertSame(2, $depositedMeta['version']);
        self::assertSame(2, $depositedMeta['globalSequence']);
        // System keys win over user metadata on collision.
        $hijacked = new OutboxRecorder($outbox)->record([
            new StoredEvent(
                eventId: str_repeat('e', 32),
                aggregateType: 'AT',
                aggregateId: 'AI',
                version: 4,
                globalSequence: 9,
                eventType: 'ev',
                payload: [],
                metadata: ['eventId' => 'user-value', 'version' => 77],
            ),
        ]);
        $entries = $outbox->due(10, PHP_INT_MAX);
        self::assertCount(3, $entries);
        $enriched = null;
        foreach ($entries as $entry) {
            if ($entry->messageType === 'ev') {
                $enriched = $entry;
            }
        }
        self::assertNotNull($enriched, 'the hijacked entry must be present');
        self::assertSame('AT', $enriched->metadata['aggregateType'], 'system metadata wins collisions');
        self::assertSame(str_repeat('e', 32), $enriched->metadata['eventId']);
        self::assertSame(4, $enriched->metadata['version']);
        self::assertSame(9, $enriched->metadata['globalSequence']);
        self::assertCount(1, $hijacked);
    }

    public function testOutboxRecorderEmptyRecordIsNoop(): void
    {
        $outbox = new InMemoryOutbox();
        $recorder = new OutboxRecorder($outbox);
        self::assertSame([], $recorder->record([]));
        self::assertSame(0, $outbox->count());
    }

    public function testRepositoryConcurrencySurfacesFromStore(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $repo = new AggregateRepository($store);

        $aggregate = EventSourcingTestAccount::open('acc-1', 1);
        $repo->persist($aggregate);

        // A stale copy of the same aggregate races with the committed one.
        $stale = EventSourcingTestAccount::open('acc-1', 1);

        try {
            $repo->persist($stale);
            self::fail('stale persist must hit the concurrency guard');
        } catch (ConcurrencyException $e) {
            self::assertSame(0, $e->expectedVersion());
            self::assertSame(1, $e->actualVersion(), 'the committed stream holds exactly the opening event');
        }
        // The stale aggregate still holds its uncommitted tail.
        self::assertSame(1, $stale->pendingCount());
    }

    public function testRepositoryFindFromSnapshotOnlyStream(): void
    {
        // Aggregate with a snapshot but zero events beyond it.
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $snapshots->save(new Snapshot('test.account', 'acc-1', 3, ['id' => 'acc-1', 'balance' => 30, 'log' => []]));
        $repo = new AggregateRepository($store, $snapshots);
        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(30, $loaded->balance());
        self::assertSame(3, $loaded->version());
    }

    public function testRepositoryReplaySkipsSnapshotVersions(): void
    {
        $store = new InMemoryEventStore();
        for ($i = 1; $i <= 3; ++$i) {
            $store->appendToStream('test.account', 'acc-1', $i - 1, new PendingEvent(
                'account.deposited',
                ['amount' => 10],
            ));
        }
        $snapshots = new InMemorySnapshotStore();
        $snapshots->save(new Snapshot('test.account', 'acc-1', 2, ['id' => 'acc-1', 'balance' => 20, 'log' => []]));
        $repo = new AggregateRepository($store, $snapshots);
        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(30, $loaded->balance(), 'only event 3 replays after snapshot version 2');
        self::assertSame(3, $loaded->version());
    }

    public function testProjectorRunsMatchingEventsAndAdvancesCheckpoint(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream(
            't',
            'a',
            0,
            new PendingEvent('order.placed', []),
            new PendingEvent('order.paid', []),
            new PendingEvent('order.shipped', []),
        );
        $checkpoints = new InMemoryCheckpointStore();
        $seen = new \stdClass();
        $seen->items = [];
        $projector = new Projector($store, $checkpoints, [$this->echoProjection('orders', ['order.placed', 'order.paid'], $seen)]);

        self::assertSame(['orders'], $projector->projectionIds());
        $applied = $projector->run();
        self::assertSame(2, $applied);
        self::assertSame(['order.placed@1', 'order.paid@2'], $seen->items);
        self::assertSame(3, $checkpoints->get('orders'), 'checkpoint advances past non-matching events too');

        // Idempotent: second run does nothing.
        $seen->items = [];
        self::assertSame(0, $projector->run());
        self::assertSame([], $seen->items);
    }

    public function testProjectorResumesFromCheckpoint(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e1'));
        $checkpoints = new InMemoryCheckpointStore();
        $checkpoints->set('p', 1);
        $seen = new \stdClass();
        $seen->items = [];
        $projector = new Projector($store, $checkpoints, [$this->echoProjection('p', ['e1'], $seen)]);
        self::assertSame(1, $projector->run());
        self::assertSame(['e1@2'], $seen->items);
    }

    public function testProjectorBatchLimitAndValidation(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream(
            't',
            'a',
            0,
            new PendingEvent('e1'),
            new PendingEvent('e1'),
            new PendingEvent('e1'),
        );
        $checkpoints = new InMemoryCheckpointStore();
        $seen = new \stdClass();
        $seen->items = [];
        $projector = new Projector($store, $checkpoints, [$this->echoProjection('p', ['e1'], $seen)]);

        self::assertSame(2, $projector->runProjection('p', 2));
        self::assertCount(2, $seen->items);
        self::assertSame(2, $checkpoints->get('p'), 'stops exactly at the batch cap');

        try {
            $projector->runProjection('p', 0);
            self::fail('batch limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('batchLimit must be >= 1 when provided (got 0).', $e->getMessage());
        }

        try {
            $projector->runProjection('missing');
            self::fail('unknown projection must fail');
        } catch (EventSourcingException $e) {
            self::assertSame("Unknown projection 'missing'.", $e->getMessage());
        }
    }

    public function testProjectorConstructorValidation(): void
    {
        $checkpoints = new InMemoryCheckpointStore();
        $seen = new \stdClass();
        $seen->items = [];

        try {
            new Projector(new InMemoryEventStore(), $checkpoints, []);
            self::fail('empty projection list must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Projector requires at least one projection.', $e->getMessage());
        }

        try {
            new Projector(new InMemoryEventStore(), $checkpoints, [$this->echoProjection('p', ['bad type'], $seen)]);
            self::fail('bad handled type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid handled event type', $e->getMessage());
        }
        $p = $this->echoProjection('p', ['e'], $seen);

        try {
            new Projector(new InMemoryEventStore(), $checkpoints, [$p, $this->echoProjection('p', ['e'], $seen)]);
            self::fail('duplicate ids must fail');
        } catch (EventSourcingException $e) {
            self::assertSame("Duplicate projection id 'p'.", $e->getMessage());
        }

        try {
            new Projector(new InMemoryEventStore(), $checkpoints, [$this->echoProjection("bad\nid", ['e'], $seen)]);
            self::fail('bad projection id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid projection id', $e->getMessage());
        }

        try {
            new Projector(new InMemoryEventStore(), $checkpoints, ['not-a-projection']); // @phpstan-ignore-line intentional non-projection input
            self::fail('non-projection must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('accepts ProjectionInterface instances (got string)', $e->getMessage());
        }
    }

    public function testProjectorFailureKeepsCheckpoint(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e1'));
        $checkpoints = new InMemoryCheckpointStore();
        $calls = new \stdClass();
        $calls->n = 0;
        $projection = new readonly class($calls) implements ProjectionInterface {
            public function __construct(private \stdClass $state) {}

            public function projectionId(): string
            {
                return 'p';
            }

            public function handles(): array
            {
                return ['e1'];
            }

            public function handle(StoredEvent $event): void
            {
                // @phpstan-ignore-next-line fixture counter is untyped by design
                if (++$this->state->n === 2) {
                    throw new \RuntimeException('projection boom');
                }
            }

            // @return iterable<object>
        };
        $projector = new Projector($store, $checkpoints, [$projection]);

        try {
            $projector->run();
            self::fail('projection failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('projection boom', $e->getMessage());
        }
        self::assertSame(1, $checkpoints->get('p'), 'checkpoint stays on the failed event');
    }

    public function testRelayProcessesAndMarksEntries(): void
    {
        $now = self::NANO;
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        $outbox->enqueue('m1', [], []);
        $outbox->enqueue('m2', [], []);
        $outbox->enqueue('m3', [], []);
        $received = [];
        $relay = new OutboxRelay($outbox, $this->bus(null, $received), static fn (): int => $now + 1);

        self::assertSame(3, $relay->relay());
        $sorted = $received;
        sort($sorted);
        self::assertSame(['m1', 'm2', 'm3'], $sorted);
        self::assertSame(0, $outbox->countPending());
    }

    public function testRelayLimitAndValidation(): void
    {
        $now = self::NANO;
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        for ($i = 0; $i < 5; ++$i) {
            $outbox->enqueue("m{$i}", [], []);
        }
        $received = [];
        $relay = new OutboxRelay($outbox, $this->bus(null, $received), static fn (): int => $now + 1);
        self::assertSame(2, $relay->relay(2));
        self::assertSame(3, $outbox->countPending());

        try {
            $relay->relay(0);
            self::fail('relay limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('relay() limit must be >= 1 (got 0).', $e->getMessage());
        }
    }

    public function testRelayRetryBackoffThenProcessed(): void
    {
        $now = self::NANO;
        $tick = 0;
        $clock = static function () use (&$tick, $now): int {
            ++$tick;

            return $now + $tick * 10_000_000_000;
        };
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        $entry = $outbox->enqueue('m', [], []);
        $received = [];

        $failBus = $this->bus(static fn (OutboxEntry $e): bool => false, $received);
        $relay = new OutboxRelay($outbox, $failBus, $clock, maxAttempts: 3, backoffBaseMs: 1000, backoffCapMs: 10_000);
        self::assertSame(0, $relay->relay(10));
        self::assertSame(1, $outbox->countPending());
        $failed = $outbox->due(10, PHP_INT_MAX)[0];
        self::assertSame(1, $failed->attempts);
        self::assertSame('listener boom', $failed->lastError);
        self::assertSame($now + 10_000_000_000 + 1_000 * 1_000_000, $failed->nextAttemptAtUnixNano);

        // Not due before the backoff elapses.
        self::assertSame([], $outbox->due(10, $now + 10_500_000_000));

        // Second failure schedules attempt 2 backoff (2000ms).
        self::assertSame(0, $relay->relay(10));
        $failed2 = $outbox->due(10, PHP_INT_MAX)[0];
        self::assertSame(2, $failed2->attempts);
        self::assertSame($now + 20_000_000_000 + 2_000 * 1_000_000, $failed2->nextAttemptAtUnixNano);

        // Third failure hits maxAttempts=3 → dead letter.
        self::assertSame(0, $relay->relay(10));
        self::assertSame(0, $outbox->countPending());
        $dead = $relay->deadLetters(10);
        self::assertCount(1, $dead);
        self::assertSame($entry->id, $dead[0]->id);
        self::assertSame(3, $dead[0]->attempts);
        self::assertSame('listener boom', $dead[0]->lastError);
    }

    public function testRelayEmptyErrorMessageFallsBackToClass(): void
    {
        $now = self::NANO;
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        $outbox->enqueue('m', [], []);
        $bus = new class implements EventBusInterface {
            public function dispatch(object $event): object
            {
                throw new \RuntimeException('', 0, new \LogicException('inner'));
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                return $this->dispatch($event);
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}

            /** @return iterable<object> */
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
        $relay = new OutboxRelay($outbox, $bus, static fn (): int => $now, maxAttempts: 1);
        self::assertSame(0, $relay->relay());
        $dead = $relay->deadLetters(10);
        self::assertSame(\RuntimeException::class, $dead[0]->lastError);
    }

    public function testRelayConstructorValidation(): void
    {
        $outbox = new InMemoryOutbox();
        $received = [];
        $bus = $this->bus(null, $received);

        try {
            new OutboxRelay($outbox, $bus, null, maxAttempts: 0);
            self::fail('maxAttempts 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('maxAttempts must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            new OutboxRelay($outbox, $bus, null, backoffBaseMs: 0);
            self::fail('backoff base 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('backoffBaseMs must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            new OutboxRelay($outbox, $bus, null, backoffBaseMs: 500, backoffCapMs: 100);
            self::fail('cap below base must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('backoffCapMs (100) must be >= backoffBaseMs (500).', $e->getMessage());
        }
        // Defaults compile.
        new OutboxRelay($outbox, $bus);
        self::addToAssertionCount(1);
    }

    public function testRelayDelayBoundaries(): void
    {
        $outbox = new InMemoryOutbox();
        $received = [];
        $relay = new OutboxRelay($outbox, $this->bus(null, $received), null, backoffBaseMs: 3, backoffCapMs: 40);
        self::assertSame(3, $relay->retryDelayMs(1));
        self::assertSame(6, $relay->retryDelayMs(2));
        self::assertSame(12, $relay->retryDelayMs(3));
        self::assertSame(24, $relay->retryDelayMs(4));
        self::assertSame(40, $relay->retryDelayMs(5));
        self::assertSame(40, $relay->retryDelayMs(100));

        try {
            $relay->retryDelayMs(0);
            self::fail('attempt 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('attempt must be >= 1 (got 0).', $e->getMessage());
        }
    }

    public function testRelayRealtimeClockDefaults(): void
    {
        $outbox = new InMemoryOutbox();
        $received = [];
        $relay = new OutboxRelay($outbox, $this->bus(null, $received));
        self::assertSame(0, $relay->relay());
    }

    // -------------------------------------------------- Projector

    /**
     * @param list<string> $handles
     */
    private function echoProjection(string $id, array $handles, \stdClass $seen): ProjectionInterface
    {
        return new readonly class($id, $handles, $seen) implements ProjectionInterface {
            /**
             * @param list<string> $handles
             */
            public function __construct(
                private string $id,
                private array $handles,
                private \stdClass $seen,
            ) {}

            public function projectionId(): string
            {
                return $this->id;
            }

            /** @return list<string> */
            public function handles(): array
            {
                return $this->handles;
            }

            public function handle(StoredEvent $event): void
            {
                // @phpstan-ignore-next-line fixture collector is untyped by design
                $this->seen->items[] = $event->eventType . '@' . $event->globalSequence;
            }
        };
    }

    // -------------------------------------------------- OutboxRelay

    /**
     * @param list<string> $received
     */
    private function bus(?callable $onMessage = null, array &$received = []): EventBusInterface
    {
        return new class($onMessage, $received) implements EventBusInterface {
            /** @var list<string> */
            private array $receivedRef; // @phpstan-ignore-line written through the by-ref binding, read by the test // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /** @var null|callable */
            private $onMessage; // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /**
             * @param list<string> $received
             */
            public function __construct(?callable $onMessage, array &$received)
            {
                $this->onMessage = $onMessage;
                $this->receivedRef = &$received;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($event instanceof OutboxMessage) {
                    $this->receivedRef[] = $event->entry->messageType;
                    if ($this->onMessage !== null && ($this->onMessage)($event->entry) === false) {
                        throw new \RuntimeException('listener boom');
                    }
                }

                return $event;
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 1));
            }

            /** @return iterable<object> */
            /** @return iterable<object> */
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
    }
}
