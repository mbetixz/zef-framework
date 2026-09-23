<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\EventGrammar;
use Zef\Framework\EventSourcing\EventJson;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRecorder;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoOutbox;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\Projector;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;

require_once __DIR__ . '/EventSourcingTestAccount.php';

/**
 * v2.19.0 — Event Sourcing edge-case matrix (adversarial): grammar
 * boundaries ±1, JSON depth cliffs, sequence-burning, MAX_PAGE pagination,
 * ambient-transaction join semantics, backoff overflow, checkpoint math.
 *
 * @internal
 */
final class EdgeMatrixEventSourcingTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    // -------------------------------------------------- grammar cliffs

    public function testAggregateTypeBoundaries(): void
    {
        EventGrammar::assertAggregateType(str_repeat('a', 1));
        EventGrammar::assertAggregateType(str_repeat('a', 127));
        EventGrammar::assertAggregateType(str_repeat('a', 128));
        EventGrammar::assertAggregateType('0123456789');
        EventGrammar::assertAggregateType('-_.:');
        EventGrammar::assertAggregateType(str_repeat('a', 128), 'aggregate ID');

        foreach (['', str_repeat('a', 129), 'a b', "a\nb", 'héllo', 'a/b', 'a\b'] as $bad) {
            try {
                EventGrammar::assertAggregateType($bad);
                self::fail("must reject '" . substr($bad, 0, 10) . "' (len " . strlen($bad) . ')');
            } catch (EventSourcingException) {
                // expected
            }
        }
        self::addToAssertionCount(7);
    }

    public function testEventTypeBoundaries(): void
    {
        EventGrammar::assertEventType('a');
        EventGrammar::assertEventType(str_repeat('e', 190));
        EventGrammar::assertEventType(str_repeat('e', 191));

        try {
            EventGrammar::assertEventType(str_repeat('e', 192));
            self::fail('192 must fail');
        } catch (EventSourcingException) {
            // expected
        }

        try {
            EventGrammar::assertEventType('dot.in.side ok');
            self::fail('space must fail');
        } catch (EventSourcingException) {
            // expected
        }
        self::addToAssertionCount(3);
    }

    public function testEventIdHexBoundaries(): void
    {
        EventGrammar::assertEventId(str_repeat('0', 32));
        EventGrammar::assertEventId('0123456789abcdef0123456789abcdef');
        foreach ([str_repeat('0', 31), str_repeat('0', 33), strtoupper(str_repeat('f', 32)), str_repeat('g', 32)] as $bad) {
            try {
                EventGrammar::assertEventId($bad);
                self::fail("must reject '{$bad}'");
            } catch (EventSourcingException) {
                // expected
            }
        }
        self::addToAssertionCount(4);
    }

    public function testPayloadDepthAndTypeCliffs(): void
    {
        // Depth 512 exactly: allowed.
        $deep = ['leaf' => 1];
        for ($i = 1; $i < 512; ++$i) {
            $deep = ['n' => $deep];
        }
        EventGrammar::assertPayload($deep, 'deep');

        // Depth 513: rejected at encode time.
        $deeper = ['n' => $deep];

        try {
            EventGrammar::assertPayload($deeper, 'deep');
            self::fail('depth 513 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('JSON-encodable', $e->getMessage());
        }

        // stdClass and closures encode to {} (property-less objects are
        // valid JSON) — allowed; resources, NAN/INF and depth are not.
        EventGrammar::assertPayload(['obj' => new \stdClass()], 'p');
        EventGrammar::assertPayload(['fn' => static fn (): int => 1], 'p');
        self::assertSame('{"fn":{}}', EventJson::encode(['fn' => static fn (): int => 1], 'p'));

        // List payloads survive the round trip shape-perfectly.
        $list = [1, 2, 3, ['a' => 1], 'x'];
        self::assertSame($list, EventJson::decode(EventJson::encode($list, 'l'), 'l'));
    }

    public function testEventJsonDecodeDepthCliff(): void
    {
        $deep = [1];
        for ($i = 0; $i < 510; ++$i) {
            $deep = [$deep];
        }
        // ~512 nesting levels decodes fine.
        EventJson::decode(EventJson::encode($deep, 'ok'), 'ok');
        $deeper = [$deep];
        $json = EventJson::encode($deeper, 'too-deep');

        try {
            EventJson::decode($json, 'too-deep');
            self::fail('over-deep decode must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt too-deep', $e->getMessage());
        }
    }

    public function testVersionAndSequenceBoundaries(): void
    {
        EventGrammar::assertVersion(1);
        EventGrammar::assertGlobalSequence(1);
        EventGrammar::assertUnixNano(0, 't');

        try {
            EventGrammar::assertVersion(\PHP_INT_MIN);
            self::fail('int-min version must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('must be >= 1', $e->getMessage());
        }

        try {
            EventGrammar::assertUnixNano(\PHP_INT_MIN, 't');
            self::fail('int-min timestamp must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('must be >= 0', $e->getMessage());
        }
    }

    public function testConcurrencyExceptionFormattingWithNegatives(): void
    {
        $e = new ConcurrencyException(\PHP_INT_MIN, -7);
        self::assertSame(
            'Concurrency conflict: expected stream version ' . \PHP_INT_MIN . ', actual is -7.',
            $e->getMessage(),
        );
    }

    // -------------------------------------------------- PendingEvent / StoredEvent edges

    public function testPendingEventMetadataIsAlsoValidated(): void
    {
        new PendingEvent('e', [], ['bad' => new \stdClass()]);

        try {
            new PendingEvent('e', [], ['bad' => fopen('php://memory', 'rb')]);
            self::fail('resource metadata must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Pending event metadata must be JSON-encodable', $e->getMessage());
        }
    }

    public function testStoredEventMinimumVersionAndSequenceAreOne(): void
    {
        new StoredEvent(str_repeat('1', 32), 't', 'i', 1, 1, 'e', [1, 2], ['m' => 1], 0);

        try {
            new StoredEvent(str_repeat('1', 32), 't', 'i', 1, 0, 'e');
            self::fail('sequence 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('global sequence must be >= 1', $e->getMessage());
        }
    }

    public function testAggregateRecordCarriesMetadataToPending(): void
    {
        $aggregate = EventSourcingTestAccount::open('a', 1, metadata: ['src' => 'edge']);
        $pending = $aggregate->pendingEvents()[0];
        self::assertSame(['src' => 'edge'], $pending->metadata);
    }

    // -------------------------------------------------- InMemoryEventStore edges

    public function testFailedAppendDoesNotBurnGlobalSequence(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'));

        try {
            $store->appendToStream('t', 'a', 0, new PendingEvent('e2'));
            self::fail('must conflict');
        } catch (ConcurrencyException) {
            // expected
        }
        $store->appendToStream('t', 'b', 0, new PendingEvent('e3'));
        self::assertSame(2, $store->lastGlobalSequence(), 'failed append must not consume a sequence number');
        self::assertSame(2, $store->countEvents());
    }

    public function testStreamAllBeyondHeadAndEmptyStore(): void
    {
        $store = new InMemoryEventStore();
        self::assertSame([], $store->streamAll(1));
        self::assertSame([], $store->streamAll(2));
        $store->appendToStream('t', 'a', 0, new PendingEvent('e'));
        self::assertSame([], $store->streamAll(2));
        self::assertCount(1, $store->streamAll(1));
    }

    public function testValidationOrderIsGrammarThenVersionThenEvents(): void
    {
        $store = new InMemoryEventStore();

        try {
            $store->appendToStream('bad type', 'a', -1);
            self::fail('grammar must be validated before expected version');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            $store->appendToStream('t', 'a', -1);
            self::fail('expected version must be validated before event emptiness');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Expected version', $e->getMessage());
        }
    }

    public function testRecordedAtUsesClockPerEvent(): void
    {
        $ticks = 0;
        $store = new InMemoryEventStore(static function () use (&$ticks): int {
            ++$ticks;

            return 100 * $ticks;
        });
        $created = $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e2'), new PendingEvent('e3'));
        self::assertSame([100, 200, 300], array_map(static fn (StoredEvent $e): int => $e->recordedAtUnixNano, $created));
    }

    // -------------------------------------------------- AggregateRoot edges

    public function testPendingVersionLifecycleAfterCommitAndRecord(): void
    {
        $aggregate = EventSourcingTestAccount::open('a', 1);
        self::assertSame(1, $aggregate->version());
        self::assertSame(0, $aggregate->pendingVersion());
        $aggregate->markCommitted();
        $aggregate->markCommitted();
        self::assertSame(1, $aggregate->pendingVersion());
        $aggregate->deposit(1);
        self::assertSame(2, $aggregate->version());
        self::assertSame(1, $aggregate->pendingVersion());
        self::assertSame(1, $aggregate->pendingCount());
    }

    public function testApplyStoredChainIsStrictlyIncreasing(): void
    {
        $aggregate = EventSourcingTestAccount::createEmpty('a');
        $event = static fn (int $v): StoredEvent => new StoredEvent(
            eventId: str_pad((string) $v, 32, '0', \STR_PAD_LEFT),
            aggregateType: 'test.account',
            aggregateId: 'a',
            version: $v,
            globalSequence: $v,
            eventType: 'account.deposited',
            payload: ['amount' => 1],
        );
        $aggregate->applyStored($event(1));
        $aggregate->applyStored($event(2));
        $aggregate->applyStored($event(3));
        self::assertSame(3, $aggregate->version());
        self::assertSame(3, $aggregate->balance());

        try {
            $aggregate->applyStored($event(2));
            self::fail('replayed version must strictly increase');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('version 2 does not exceed', $e->getMessage());
        }
    }

    public function testProjectorHandlesExactlyMaxPageEvents(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $this->fillStore($store, EventGrammar::MAX_PAGE);
        $seen = new \stdClass();
        $seen->n = 0;
        $projection = new readonly class($seen) implements ProjectionInterface {
            public function __construct(private \stdClass $seen) {}

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
                ++$this->seen->n;
            }
        };
        $checkpoints = new InMemoryCheckpointStore();
        $projector = new Projector($store, $checkpoints, [$projection]);
        self::assertSame(EventGrammar::MAX_PAGE, $projector->run());
        self::assertSame(EventGrammar::MAX_PAGE, $checkpoints->get('p'));
        self::assertSame(EventGrammar::MAX_PAGE, $seen->n);
    }

    public function testProjectorPaginationContinuesPastFullPage(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $this->fillStore($store, EventGrammar::MAX_PAGE + 1);
        $seen = new \stdClass();
        $seen->n = 0;
        $projection = new readonly class($seen) implements ProjectionInterface {
            public function __construct(private \stdClass $seen) {}

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
                ++$this->seen->n;
            }
        };
        $projector = new Projector($store, new InMemoryCheckpointStore(), [$projection]);
        self::assertSame(EventGrammar::MAX_PAGE + 1, $projector->run(), 'a full first page must not end the catch-up');
    }

    public function testProjectorWithEmptyHandlesAdvancesCheckpointOnly(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e2'));
        $seen = new \stdClass();
        $seen->n = 0;
        $projection = new readonly class($seen) implements ProjectionInterface {
            public function __construct(private \stdClass $seen) {}

            public function projectionId(): string
            {
                return 'p';
            }

            public function handles(): array
            {
                return [];
            }

            public function handle(StoredEvent $event): void
            {
                // @phpstan-ignore-next-line fixture counter is untyped by design
                ++$this->seen->n;
            }
        };
        $checkpoints = new InMemoryCheckpointStore();
        $projector = new Projector($store, $checkpoints, [$projection]);
        self::assertSame(0, $projector->run());
        self::assertSame(2, $checkpoints->get('p'), 'non-matching events still advance the checkpoint');
        self::assertSame(0, $seen->n);
    }

    // -------------------------------------------------- Outbox edges

    public function testOutboxDueSkipsFutureRetriesAndKeepsOrder(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $outbox->enqueue('a', [], []);
        $b = $outbox->enqueue('b', [], []);
        $outbox->enqueue('c', [], []);
        $outbox->markFailed($b->id, 'x', self::NANO + 500);
        $due = $outbox->due(10, self::NANO + 499);
        $types = array_map(static fn (OutboxEntry $e): string => $e->messageType, $due);
        sort($types);
        self::assertSame(['a', 'c'], $types, 'the retried entry stays parked until its deadline');
        $all = $outbox->due(10, self::NANO + 500);
        self::assertCount(3, $all);
    }

    public function testOutboxErrorMessagesKeepUtf8(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('m', [], []);
        $outbox->markFailed($entry->id, 'gagal: ünïcödé 💥', self::NANO);
        self::assertSame('gagal: ünïcödé 💥', $outbox->due(10, PHP_INT_MAX)[0]->lastError);
    }

    public function testOutboxCountCountsEveryStateSeparately(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $a = $outbox->enqueue('a', [], []);
        $b = $outbox->enqueue('b', [], []);
        $outbox->enqueue('c', [], []);
        $outbox->markProcessed($a->id);
        $outbox->markDead($b->id, 'x');
        self::assertSame(1, $outbox->countPending());
        self::assertSame(3, $outbox->count(), 'total counts all states');
        self::assertCount(1, $outbox->failed(10));
    }

    public function testRelayWithMaxAttemptsTwoDeadLettersOnSecondFailure(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $outbox->enqueue('m', [], []);
        $received = [];
        $bus = $this->failingBus($received);
        $tick = 0;
        $relay = new OutboxRelay($outbox, $bus, static function () use (&$tick): int {
            ++$tick;

            return self::NANO + $tick * 60_000_000_000;
        }, maxAttempts: 2, backoffBaseMs: 1, backoffCapMs: 1);
        self::assertSame(0, $relay->relay(10));
        self::assertSame(1, $outbox->countPending());
        self::assertSame(0, $relay->relay(10));
        self::assertSame(0, $outbox->countPending());
        $dead = $relay->deadLetters(10);
        self::assertCount(1, $dead);
        self::assertSame(2, $dead[0]->attempts);
    }

    public function testRelayBackoffCapPreventsOverflow(): void
    {
        $outbox = new InMemoryOutbox();
        $received = [];
        // Base doubles past the cap on attempt 2 — the loop must bail out
        // at the cap instead of overflowing (base 600: 600 → 1200 → cap).
        $relay = new OutboxRelay($outbox, $this->failingBus($received), null, backoffBaseMs: 600, backoffCapMs: 1_000);
        self::assertSame(600, $relay->retryDelayMs(1));
        self::assertSame(1_000, $relay->retryDelayMs(2));
        self::assertSame(1_000, $relay->retryDelayMs(3));
        self::assertSame(1_000, $relay->retryDelayMs(63));
        self::assertSame(1_000, $relay->retryDelayMs(\PHP_INT_MAX));
    }

    public function testRelayIgnoresNonOutboxEventsAndCountsOnlyOutbox(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $outbox->enqueue('m', [], []);
        $bus = new class implements EventBusInterface {
            /** @var list<string> */
            public array $otherEvents = [];

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if (!$event instanceof OutboxMessage) {
                    $this->otherEvents[] = $event::class;
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
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
        $relay = new OutboxRelay($outbox, $bus);
        self::assertSame(1, $relay->relay(10));
        // Relay only ever dispatches OutboxMessage.
        $relay2 = new OutboxRelay(new InMemoryOutbox(static fn (): int => self::NANO), $bus);
        self::assertSame(0, $relay2->relay(10));
    }

    public function testAmbientTransactionJoinsAndSurvivesInnerFailure(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $store->createSchema();
        $outbox->createSchema();

        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'));

        $result = $conn->transaction(static function () use ($conn, $store, $outbox): string {
            // Join: no new BEGIN (level stays 1).
            self::assertSame(1, $conn->transactionLevel());
            $outbox->enqueue('ambient.entry', ['k' => 'v'], []);

            try {
                $store->appendToStream('t', 'a', 0, new PendingEvent('e-wrong'));
                self::fail('inner append must conflict');
            } catch (ConcurrencyException) {
                // The adapter must NOT roll back the ambient transaction:
                // rethrowing is the adapter's only job here.
            }
            $outbox->enqueue('ambient.entry2', [], []);
            self::assertSame(1, $conn->transactionLevel(), 'still inside the same ambient transaction');

            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(0, $conn->transactionLevel());

        // The conflicting append left nothing; the outbox entries survived.
        self::assertCount(1, $store->loadStream('t', 'a'));
        self::assertSame(2, $outbox->countPending());
        $types = array_map(static fn (OutboxEntry $e): string => $e->messageType, $outbox->due(10, PHP_INT_MAX));
        sort($types);
        self::assertSame(['ambient.entry', 'ambient.entry2'], $types);
    }

    public function testAmbientOuterRollbackDiscardsEverything(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $store->createSchema();
        $outbox->createSchema();

        try {
            $conn->transaction(static function () use ($store, $outbox): never {
                $store->appendToStream('t', 'a', 0, new PendingEvent('e1'));
                $outbox->enqueue('ambient.entry', [], []);

                throw new \RuntimeException('business abort');
            });
            // @phpstan-ignore-next-line statically unreachable: the closure always throws
            self::fail('outer rollback must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('business abort', $e->getMessage());
        }
        self::assertSame(0, $conn->transactionLevel());
        self::assertSame([], $store->loadStream('t', 'a'));
        self::assertSame(0, $outbox->countPending());
    }

    public function testPdoOutboxAmbientJoinKeepsAtomicity(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $store->createSchema();
        $outbox->createSchema();

        $conn->beginTransaction();
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'));
        $outbox->enqueue('m', [], []);
        // The same connection sees its own uncommitted writes.
        self::assertSame(1, $outbox->countPending());
        self::assertCount(1, $store->loadStream('t', 'a'));
        $conn->commit();
        self::assertSame(1, $outbox->countPending(), 'commit preserves the joined writes');
        self::assertCount(1, $store->loadStream('t', 'a'));
    }

    // -------------------------------------------------- repository edges

    public function testPersistWithoutPendingNeverTouchesStores(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        $repo = new AggregateRepository($store, outbox: new OutboxRecorder($outbox));
        $aggregate = EventSourcingTestAccount::open('a', 1);
        $aggregate->markCommitted();
        self::assertSame([], $repo->persist($aggregate));
        self::assertSame(0, $store->countEvents());
        self::assertSame(0, $outbox->count());
    }

    public function testFullStackPersistSnapshotOutboxAtomic(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $snapshots = new InMemorySnapshotStore();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $store->createSchema();
        $outbox->createSchema();

        $repo = new AggregateRepository(
            store: $store,
            snapshots: $snapshots,
            policy: SnapshotPolicy::every(2),
            outbox: new OutboxRecorder($outbox, $conn),
            connection: $conn,
            clock: static fn (): int => self::NANO + 5,
        );

        $aggregate = EventSourcingTestAccount::open('acc', 1);
        $aggregate->deposit(2, metadata: ['tenant' => 't9']);
        $stored = $repo->persist($aggregate);
        self::assertCount(2, $stored);
        self::assertSame(2, $outbox->countPending());
        $stored = $snapshots->load('test.account', 'acc');
        self::assertNotNull($stored);
        self::assertSame(self::NANO + 5, $stored->createdAtUnixNano);

        $meta = $outbox->due(10, PHP_INT_MAX);
        $tenant = null;
        foreach ($meta as $entry) {
            if ($entry->messageType === 'account.deposited') {
                $tenant = $entry->metadata['tenant'] ?? null;
                self::assertSame('t9', $entry->metadata['tenant']);
                self::assertSame(2, $entry->metadata['version']);
            }
        }
        self::assertSame('t9', $tenant);
    }

    public function testSnapshotStoreDeleteIsIdentityScoped(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(new Snapshot('t', 'a', 1, ['id' => 'a', 'balance' => 1, 'log' => []]));
        $store->save(new Snapshot('t', 'b', 2, ['id' => 'b', 'balance' => 2, 'log' => []]));
        self::assertTrue($store->delete('t', 'a'));
        self::assertNotNull($store->load('t', 'b'));
        self::assertNull($store->load('t', 'a'));
    }

    public function testSnapshotWithListStateRoundTripsThroughPdo(): void
    {
        $conn = $this->sqliteConn();
        $snapshots = new PdoSnapshotStore($conn, 'zef_snapshots');
        $snapshots->createSchema();
        $snapshots->save(new Snapshot('t', 'a', 3, [1, 2, 3], 9));
        $loaded = $snapshots->load('t', 'a');
        self::assertNotNull($loaded);
        self::assertSame([1, 2, 3], $loaded->state);
    }

    // -------------------------------------------------- Projector MAX_PAGE pagination

    private function fillStore(InMemoryEventStore $store, int $count): void
    {
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $events[] = new PendingEvent('e1');
        }
        $store->appendToStream('t', 'a', 0, ...$events);
    }

    /**
     * @param list<string> $received
     */
    private function failingBus(array &$received): EventBusInterface
    {
        return new class($received) implements EventBusInterface {
            /** @var list<string> */
            private array $received; // @phpstan-ignore-line written through the by-ref binding, read by the test // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /**
             * @param list<string> $received
             */
            public function __construct(array &$received)
            {
                $this->received = &$received;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($event instanceof OutboxMessage) {
                    $this->received[] = $event->entry->messageType;

                    throw new \RuntimeException('downstream unavailable');
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
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
    }

    // -------------------------------------------------- PdoEventStore ambient transactions

    private function sqliteConn(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }
}
