<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoOutbox;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\RowCast;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.23.0 Event Sourcing Hardening: stream-continuity guard, snapshot
 * regression guard (CAS), outbox dead-letter requeue, and the PDO schema
 * backstops UNIQUE(event_id) / UNIQUE(global_sequence).
 *
 * @internal
 */
final class EventSourcingHardeningTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    public function testReplayFailsOnStreamGap(): void
    {
        $aggregate = EventSourcingTestAccount::createEmpty('a-1');
        $aggregate->applyStored($this->stored('account.opened', 1, 1, ['initial' => 5]));
        $aggregate->applyStored($this->stored('account.deposited', 2, 2, ['amount' => 7]));

        try {
            // version 3 was lost on disk (truncated stream): 4 arrives instead
            $aggregate->applyStored($this->stored('account.deposited', 4, 4, ['amount' => 1]));
            self::fail('a stream gap must abort the replay');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt stream', $e->getMessage());
            self::assertStringContainsString('expected stored version 3, got 4', $e->getMessage());
        }
        self::assertSame(2, $aggregate->version(), 'failed replay must not advance the version');
        self::assertSame(12, $aggregate->balance(), 'state before the gap stays intact');
    }

    public function testSnapshotSeededReplayRequiresExactNextVersion(): void
    {
        $source = EventSourcingTestAccount::open('a-1', 5);
        $aggregate = EventSourcingTestAccount::restoreFromSnapshot($source->snapshotState(), 1);

        try {
            $aggregate->applyStored($this->stored('account.deposited', 3, 3, ['amount' => 7]));
            self::fail('the first replayed event must be exactly snapshot version + 1');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('expected stored version 2, got 3', $e->getMessage());
        }

        $aggregate->applyStored($this->stored('account.deposited', 2, 2, ['amount' => 7]));
        self::assertSame(12, $aggregate->balance());
    }

    public function testInMemorySnapshotStoreNeverRegresses(): void
    {
        $store = new InMemorySnapshotStore();
        $newer = new Snapshot('test.account', 'a-1', 10, ['state' => 'v10'], self::NANO);
        $store->save($newer);

        $older = new Snapshot('test.account', 'a-1', 9, ['state' => 'v9'], self::NANO);
        $store->save($older);
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(10, $loaded->version, 'a strictly older snapshot must be discarded');
        self::assertSame(['state' => 'v10'], $loaded->state);

        $equalRefresh = new Snapshot('test.account', 'a-1', 10, ['state' => 'v10-refreshed'], self::NANO + 1);
        $store->save($equalRefresh);
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(['state' => 'v10-refreshed'], $loaded->state, 'same-version saves stay allowed (idempotent refresh)');

        $newest = new Snapshot('test.account', 'a-1', 11, ['state' => 'v11'], self::NANO + 2);
        $store->save($newest);
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(11, $loaded->version);
    }

    public function testPdoSnapshotStoreNeverRegresses(): void
    {
        $store = new PdoSnapshotStore($this->sqliteConn(), 'zef_snapshots_cas');
        $store->createSchema();
        $store->save(new Snapshot('test.account', 'a-1', 10, ['state' => 'v10'], self::NANO));

        $store->save(new Snapshot('test.account', 'a-1', 9, ['state' => 'v9'], self::NANO));
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(10, $loaded->version, 'a strictly older snapshot must be discarded');
        self::assertSame(['state' => 'v10'], $loaded->state);

        $store->save(new Snapshot('test.account', 'a-1', 11, ['state' => 'v11'], self::NANO + 1));
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(11, $loaded->version);
        self::assertSame(['state' => 'v11'], $loaded->state);
    }

    public function testInMemoryRequeueRevivesDeadLetter(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);
        $outbox->markDead($entry->id, 'downstream outage');

        $revived = $outbox->requeue($entry->id, self::NANO + 5);
        self::assertTrue($revived->isPending());
        self::assertSame(0, $revived->attempts, 'a requeued entry earns a fresh retry budget');
        self::assertSame(self::NANO + 5, $revived->nextAttemptAtUnixNano);
        self::assertSame('downstream outage', $revived->lastError, 'the dead-letter reason stays readable');
        self::assertSame(1, $outbox->countPending());
        self::assertSame([$revived], $outbox->due(10, self::NANO + 5));
    }

    public function testInMemoryRequeueGuardRails(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $pending = $outbox->enqueue('order.placed', []);

        try {
            $outbox->requeue($pending->id);
            self::fail('requeueing a pending entry is a caller bug');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Only failed entries can be requeued', $e->getMessage());
        }

        $outbox->markProcessed($pending->id);

        try {
            $outbox->requeue($pending->id);
            self::fail('requeueing a processed entry is a caller bug');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString("entry '{$pending->id}' is 'processed'", $e->getMessage());
        }

        try {
            $outbox->requeue(str_repeat('f', 32));
            self::fail('requeueing an unknown entry must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Unknown outbox entry', $e->getMessage());
        }
    }

    public function testPdoRequeueRevivesDeadLetter(): void
    {
        $outbox = new PdoOutbox($this->sqliteConn(), 'zef_outbox_cas', static fn (): int => self::NANO);
        $outbox->createSchema();
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);
        $outbox->markDead($entry->id, 'downstream outage');

        $revived = $outbox->requeue($entry->id, self::NANO + 7);
        self::assertTrue($revived->isPending());
        self::assertSame(0, $revived->attempts);
        self::assertSame(self::NANO + 7, $revived->nextAttemptAtUnixNano);
        self::assertSame('downstream outage', $revived->lastError);
        self::assertSame(1, $outbox->countPending());
        self::assertCount(1, $outbox->due(10, self::NANO + 7));
    }

    public function testPdoRequeueGuardRails(): void
    {
        $outbox = new PdoOutbox($this->sqliteConn(), 'zef_outbox_cas2', static fn (): int => self::NANO);
        $outbox->createSchema();
        $pending = $outbox->enqueue('order.placed', []);

        try {
            $outbox->requeue($pending->id);
            self::fail('requeueing a pending entry is a caller bug');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString("entry '{$pending->id}' is 'pending'", $e->getMessage());
        }

        try {
            $outbox->requeue(str_repeat('e', 32));
            self::fail('requeueing an unknown entry must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Unknown outbox entry', $e->getMessage());
        }
    }

    public function testRelayRequeueDeadLettersRestoresDelivery(): void
    {
        $now = self::NANO;
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        $outbox->enqueue('order.placed', ['id' => 1]);
        $bus = $this->failingBus();
        $relay = new OutboxRelay($outbox, $bus, static fn (): int => $now, maxAttempts: 1);

        self::assertSame(0, $relay->relay());
        self::assertCount(1, $relay->deadLetters(10), 'one failed dispatch with maxAttempts 1 dead-letters the entry');

        self::assertSame(1, $relay->requeueDeadLetters());
        self::assertSame(1, $outbox->countPending());

        $bus->fail = false;
        self::assertSame(1, $relay->relay(), 'after the root cause is fixed the requeued entry delivers');
        self::assertSame(0, $outbox->countPending());
        self::assertSame([], $relay->deadLetters(10));
    }

    public function testRelayRequeueDeadLettersLimitValidation(): void
    {
        $relay = new OutboxRelay(new InMemoryOutbox(), $this->failingBus(false), static fn (): int => self::NANO);

        try {
            $relay->requeueDeadLetters(0);
            self::fail('requeueDeadLetters limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('requeueDeadLetters() limit must be >= 1 (got 0).', $e->getMessage());
        }
        self::assertSame(0, $relay->requeueDeadLetters(10), 'no dead letters means nothing to requeue');
    }

    public function testEventTableRejectsDuplicateEventId(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events_hard', static fn (): int => self::NANO);
        $store->createSchema();
        $aggregate = EventSourcingTestAccount::open('a-1', 5);
        $store->appendToStream($aggregate::aggregateType(), $aggregate->aggregateId(), 0, ...$aggregate->pendingEvents());

        try {
            $this->sqliteInsertEvent($conn, 'zef_events_hard', eventId: str_repeat('a', 32), version: 1, sequence: 99);
            self::fail('UNIQUE(event_id) must reject a colliding event id');
        } catch (\Throwable $e) {
            self::assertStringContainsString('UNIQUE constraint failed', $this->deepest($e));
        }
    }

    public function testEventTableRejectsDuplicateGlobalSequence(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events_hard2', static fn (): int => self::NANO);
        $store->createSchema();
        $aggregate = EventSourcingTestAccount::open('a-1', 5);
        $store->appendToStream($aggregate::aggregateType(), $aggregate->aggregateId(), 0, ...$aggregate->pendingEvents());

        try {
            $this->sqliteInsertEvent($conn, 'zef_events_hard2', eventId: str_repeat('b', 32), version: 1, sequence: 1);
            self::fail('UNIQUE(global_sequence) must reject a colliding projection currency');
        } catch (\Throwable $e) {
            self::assertStringContainsString('UNIQUE constraint failed', $this->deepest($e));
        }
    }

    /**
     * The corrupt-stream message names every coordinate an operator needs to
     * locate the damage: the event id, the expected and actual stored version
     * and the version the aggregate reached before the replay aborted.
     */
    public function testReplayGapMessageIsExact(): void
    {
        $aggregate = EventSourcingTestAccount::createEmpty('a-1');
        $aggregate->applyStored($this->stored('account.opened', 1, 1, ['initial' => 5]));
        $aggregate->applyStored($this->stored('account.deposited', 2, 2, ['amount' => 7]));

        $gap = $this->stored('account.deposited', 4, 4, ['amount' => 1]);

        try {
            $aggregate->applyStored($gap);
            self::fail('a stream gap must abort the replay');
        } catch (EventSourcingException $e) {
            self::assertSame(
                'Corrupt stream for event ' . $gap->eventId
                . ': expected stored version 3, got 4 (aggregate version 2).',
                $e->getMessage(),
            );
        }
    }

    /**
     * Retry backoff doubles from the base and clamps at the cap; the clamp
     * is inclusive (a delay that LANDS on the cap stays there) and the cap
     * is never exceeded afterwards.
     */
    public function testRelayRetryDelayMatrix(): void
    {
        $relay = new OutboxRelay(new InMemoryOutbox(), $this->failingBus(false), static fn (): int => self::NANO);
        self::assertSame(1_000, $relay->retryDelayMs(1));
        self::assertSame(2_000, $relay->retryDelayMs(2));
        self::assertSame(4_000, $relay->retryDelayMs(3));
        self::assertSame(32_000, $relay->retryDelayMs(6));
        self::assertSame(60_000, $relay->retryDelayMs(7), '64k overshoots the cap and clamps');
        self::assertSame(60_000, $relay->retryDelayMs(8));
        self::assertSame(60_000, $relay->retryDelayMs(9));

        $tight = new OutboxRelay(new InMemoryOutbox(), $this->failingBus(false), static fn (): int => self::NANO, 3, 3_000, 12_000);
        self::assertSame(3_000, $tight->retryDelayMs(1));
        self::assertSame(6_000, $tight->retryDelayMs(2));
        self::assertSame(12_000, $tight->retryDelayMs(3), 'a delay that lands exactly on the cap is kept');
        self::assertSame(12_000, $tight->retryDelayMs(4));

        try {
            $relay->retryDelayMs(0);
            self::fail('attempt 0 is not a valid 1-based attempt number');
        } catch (EventSourcingException $e) {
            self::assertSame('attempt must be >= 1 (got 0).', $e->getMessage());
        }
    }

    /**
     * deadLetters()/requeueDeadLetters() default to a 100-entry batch: with
     * 101 dead letters exactly the first 100 are returned/requeued per call.
     */
    public function testDeadLetterBatchingDefaultsTo100(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        for ($i = 0; $i < 101; ++$i) {
            $outbox->enqueue('order.placed', ['i' => $i]);
        }
        $relay = new OutboxRelay($outbox, $this->failingBus(), static fn (): int => self::NANO, maxAttempts: 1);
        self::assertSame(0, $relay->relay(200), 'nothing processes: every dispatch fails');
        self::assertCount(101, $outbox->failed(200), 'all 101 entries are dead after one attempt');

        self::assertCount(100, $relay->deadLetters(), 'the deadLetters() default batch is 100');
        self::assertSame(100, $relay->requeueDeadLetters(), 'the requeueDeadLetters() default batch is 100');
        self::assertSame(100, $outbox->countPending(), '100 entries returned to pending');
        self::assertCount(1, $relay->deadLetters(10), 'one dead letter remains');

        self::assertSame(1, $relay->requeueDeadLetters(1), 'an explicit limit of 1 is valid');
    }

    /**
     * The in-memory outbox must honour an INJECTED clock when no explicit
     * timestamp is passed — a silently ignored clock would make tests and
     * replay determinism impossible.
     */
    public function testInMemoryOutboxUsesInjectedClockForEnqueue(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);
        self::assertSame(self::NANO, $entry->createdAtUnixNano);
        self::assertSame(self::NANO, $entry->nextAttemptAtUnixNano);
    }

    /**
     * failed() ties (same createdAt) are broken by entry id ascending — the
     * deterministic order an operator expects when scanning dead letters.
     */
    public function testInMemoryFailedOrdersByIdOnTie(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $relay = new OutboxRelay($outbox, $this->failingBus(), static fn (): int => self::NANO, maxAttempts: 1);
        for ($i = 0; $i < 4; ++$i) {
            $outbox->enqueue('order.placed', ['i' => $i]);
        }
        $relay->relay();

        $ids = array_map(static fn (OutboxEntry $e): string => $e->id, $outbox->failed(10));
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'same-createdAt dead letters come back in id-ascending order');
    }

    /**
     * The in-memory requeue rejects every non-failed state with the exact
     * operator-facing message, and unknown ids are reported verbatim.
     */
    public function testInMemoryRequeueMessagesAreExact(): void
    {
        $now = self::NANO;
        $outbox = new InMemoryOutbox(static fn (): int => $now);
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);

        try {
            $outbox->requeue($entry->id, $now);
            self::fail('requeueing a pending entry is a caller bug');
        } catch (EventSourcingException $e) {
            self::assertSame("Only failed entries can be requeued (entry '{$entry->id}' is 'pending').", $e->getMessage());
        }

        $outbox->markProcessed($entry->id);

        try {
            $outbox->requeue($entry->id, $now);
            self::fail('requeueing a processed entry is a caller bug');
        } catch (EventSourcingException $e) {
            self::assertSame("Only failed entries can be requeued (entry '{$entry->id}' is 'processed').", $e->getMessage());
        }

        $unknownId = str_repeat('e', 32);

        try {
            $outbox->requeue($unknownId, $now);
            self::fail('requeueing an unknown entry must fail');
        } catch (EventSourcingException $e) {
            self::assertSame("Unknown outbox entry '{$unknownId}'.", $e->getMessage());
        }
    }

    /**
     * When no explicit timestamp is passed, requeue() falls back to the
     * INJECTED clock — the port contract ("the adapter's clock decides")
     * must hold, otherwise requeued entries become invisible until the
     * realtime clock catches up with a frozen test clock.
     */
    public function testInMemoryRequeueFallsBackToInjectedClock(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);
        $relay = new OutboxRelay($outbox, $this->failingBus(), static fn (): int => self::NANO, maxAttempts: 1);
        $relay->relay();
        self::assertCount(1, $outbox->failed(10));

        $requeued = $outbox->requeue($entry->id);
        self::assertSame(OutboxEntry::STATUS_PENDING, $requeued->status);
        self::assertSame(self::NANO, $requeued->nextAttemptAtUnixNano, 'the adapter clock decides the next attempt');
        self::assertSame(0, $requeued->attempts, 'the retry budget is fully restored');
        self::assertNotNull($requeued->lastError, 'the original error stays visible for post-mortem');
    }

    /**
     * The PDO requeue validates the caller-supplied clock value before it
     * touches the database.
     */
    public function testPdoRequeueRejectsNegativeNextAttempt(): void
    {
        $outbox = new PdoOutbox($this->sqliteConn(), 'zef_outbox_neg', static fn (): int => self::NANO);
        $outbox->createSchema();
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);

        try {
            $outbox->requeue($entry->id, -1);
            self::fail('a negative nextAttemptAtUnixNano must be rejected');
        } catch (EventSourcingException $e) {
            self::assertSame('nextAttemptAtUnixNano must be >= 0 (got -1).', $e->getMessage());
        }
    }

    /**
     * If the entry disappears between the requeue UPDATE and the re-read
     * (an external reaper, a misbehaving trigger), the store reports it
     * instead of returning a fabricated entry.
     */
    public function testPdoRequeueReportsVanishedEntry(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox_vanish', static fn (): int => self::NANO);
        $outbox->createSchema();
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);

        $relay = new OutboxRelay($outbox, $this->failingBus(), static fn (): int => self::NANO, maxAttempts: 1);
        $relay->relay();
        self::assertCount(1, $outbox->failed(10));

        $conn->execute(SqlQuery::raw(
            'CREATE TRIGGER "zef_outbox_vanish_reap" AFTER UPDATE ON "zef_outbox_vanish" '
            . "WHEN NEW.status = 'pending' BEGIN DELETE FROM \"zef_outbox_vanish\" WHERE id = NEW.id; END",
        ));

        try {
            $outbox->requeue($entry->id, self::NANO);
            self::fail('a vanished entry must be reported, not fabricated');
        } catch (EventSourcingException $e) {
            self::assertSame("Outbox entry '{$entry->id}' vanished during requeue.", $e->getMessage());
        }
    }

    /**
     * Without an injected clock the store falls back to realtime nanoseconds
     * — the value must be an int in the unix-nano magnitude, not seconds or
     * a float, because every scheduling decision compares these numbers.
     */
    public function testPdoOutboxDefaultClockIsRealtimeUnixNano(): void
    {
        $outbox = new PdoOutbox($this->sqliteConn(), 'zef_outbox_clock');
        $outbox->createSchema();
        $before = (int) (microtime(true) * 1_000_000_000);
        $entry = $outbox->enqueue('order.placed', ['id' => 1]);
        $after = (int) (microtime(true) * 1_000_000_000);

        self::assertGreaterThanOrEqual($before, $entry->createdAtUnixNano, 'timestamps are nanoseconds, not seconds or floats');
        self::assertLessThanOrEqual($after, $entry->createdAtUnixNano);
    }

    /**
     * The v2.23.0 schema backstops are named constraints — an operator
     * migration must be able to verify both indexes exist under stable names.
     */
    public function testEventStoreSchemaConstraintNames(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events_names', static fn (): int => self::NANO);
        $store->createSchema();

        $ddl = RowCast::string($conn->fetchOne(SqlQuery::raw(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'zef_events_names'",
        ))['sql'] ?? null);
        self::assertStringContainsString('CONSTRAINT "uq_zef_events_names_stream"', $ddl);
        self::assertStringContainsString('CONSTRAINT "uq_zef_events_names_event_id"', $ddl);
        self::assertStringContainsString('CONSTRAINT "uq_zef_events_names_global"', $ddl);
        self::assertStringNotContainsString('uq__', $ddl, 'the table name must be part of every constraint name');
    }

    /** The snapshot store identity constraint carries a stable, testable name. */
    public function testSnapshotStoreSchemaConstraintName(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoSnapshotStore($conn, 'zef_snapshots_names');
        $store->createSchema();

        $ddl = RowCast::string($conn->fetchOne(SqlQuery::raw(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'zef_snapshots_names'",
        ))['sql'] ?? null);
        self::assertStringContainsString('CONSTRAINT "uq_zef_snapshots_names_identity"', $ddl);
        self::assertStringNotContainsString('uq__', $ddl, 'the table name must be part of the constraint name');
    }

    /**
     * Without an injected clock the store stamps events with realtime
     * nanoseconds — recorded_at drives projection ordering, so a seconds-
     * or seconds-scaled value would silently corrupt the global sequence
     * assumptions of checkpointed consumers.
     */
    public function testEventStoreDefaultClockIsRealtimeUnixNano(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events_clock');
        $store->createSchema();
        $before = (int) (microtime(true) * 1_000_000_000);
        $aggregate = EventSourcingTestAccount::open('a-1', 5);
        $store->appendToStream($aggregate::aggregateType(), $aggregate->aggregateId(), 0, ...$aggregate->pendingEvents());
        $after = (int) (microtime(true) * 1_000_000_000);

        $row = $conn->fetchOne(SqlQuery::raw('SELECT "recorded_at" FROM "zef_events_clock" LIMIT 1'));
        $recordedAt = RowCast::int($row['recorded_at'] ?? null);
        self::assertGreaterThanOrEqual($before, $recordedAt, 'recorded_at is nanoseconds, not seconds or floats');
        self::assertLessThanOrEqual($after, $recordedAt);
    }

    /** Grammar is enforced at the store boundary, not only in the repository. */
    public function testEventStoreAppendValidatesGrammar(): void
    {
        $store = new PdoEventStore($this->sqliteConn(), 'zef_events_grammar', static fn (): int => self::NANO);
        $store->createSchema();
        $aggregate = EventSourcingTestAccount::open('a-1', 5);

        try {
            $store->appendToStream('bad type!', 'a-1', 0, ...$aggregate->pendingEvents());
            self::fail('an invalid aggregate type must be rejected at the store boundary');
        } catch (EventSourcingException $e) {
            self::assertSame("Invalid aggregate type 'bad type!' (1..128 bytes of [A-Za-z0-9._:-]).", $e->getMessage());
        }

        try {
            $store->appendToStream('test.account', 'bad id!', 0, ...$aggregate->pendingEvents());
            self::fail('an invalid aggregate id must be rejected at the store boundary');
        } catch (EventSourcingException $e) {
            self::assertSame("Invalid aggregate ID 'bad id!' (1..128 bytes of [A-Za-z0-9._:-]).", $e->getMessage());
        }
    }

    /**
     * An append inside an ambient transaction JOINS it — no nested begin and
     * no self-commit: the caller stays in control of the atomic boundary.
     */
    public function testAppendJoinsAmbientTransaction(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events_ambient', static fn (): int => self::NANO);
        $store->createSchema();
        $aggregate = EventSourcingTestAccount::open('a-1', 5);

        $conn->beginTransaction();
        $store->appendToStream($aggregate::aggregateType(), $aggregate->aggregateId(), 0, ...$aggregate->pendingEvents());
        self::assertSame(1, $conn->transactionLevel(), 'the append must not open or commit its own transaction');
        $conn->rollBack();

        $left = $conn->fetchOne(SqlQuery::raw('SELECT COUNT(*) AS c FROM "zef_events_ambient"'));
        self::assertSame(0, RowCast::int($left['c'] ?? null), 'the rolled-back ambient transaction takes the events with it');
    }

    /**
     * A save inside an ambient transaction JOINS it — no nested begin and no
     * self-commit; rolling the ambient transaction back removes the snapshot.
     */
    public function testSnapshotSaveJoinsAmbientTransaction(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoSnapshotStore($conn, 'zef_snapshots_ambient');
        $store->createSchema();

        $conn->beginTransaction();
        $store->save(new Snapshot('test.account', 'a-1', 5, ['balance' => 5], self::NANO));
        self::assertSame(1, $conn->transactionLevel(), 'the save must not open or commit its own transaction');
        $conn->rollBack();

        self::assertNull($store->load('test.account', 'a-1'), 'the rolled-back ambient transaction takes the snapshot with it');
    }

    /**
     * Saving the SAME version again is an idempotent refresh — the fresher
     * state payload wins. A guard that rejected equal versions would make
     * snapshot refresh impossible.
     */
    public function testPdoSnapshotSameVersionRefreshWins(): void
    {
        $store = new PdoSnapshotStore($this->sqliteConn(), 'zef_snapshots_refresh');
        $store->createSchema();

        $store->save(new Snapshot('test.account', 'a-1', 5, ['balance' => 5], self::NANO));
        $store->save(new Snapshot('test.account', 'a-1', 5, ['balance' => 9], self::NANO + 1));

        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(['balance' => 9], $loaded->state, 'the equal-version refresh replaces the stored state');
        self::assertSame(5, $loaded->version);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stored(string $type, int $version, int $sequence, array $payload = []): StoredEvent
    {
        return new StoredEvent(
            eventId: str_pad((string) $version, 32, '0', \STR_PAD_LEFT),
            aggregateType: 'test.account',
            aggregateId: 'a-1',
            version: $version,
            globalSequence: $sequence,
            eventType: $type,
            payload: $payload,
        );
    }

    /**
     * Bus whose dispatch fails (or not) on command — the flip drives the
     * dead-letter → requeue → deliver cycle.
     */
    private function failingBus(bool $fail = true): FlippableBus
    {
        return new FlippableBus($fail);
    }

    private function sqliteInsertEvent(PdoConnection $conn, string $table, string $eventId, int $version, int $sequence): void
    {
        $conn->execute(SqlQuery::raw(
            'INSERT INTO "' . $table . '" (global_sequence, event_id, aggregate_type, aggregate_id, version, event_type, payload, metadata, recorded_at) VALUES ('
            . $sequence . ", '" . $eventId . "', 'test.account', 'a-1', " . $version . ", 'account.deposited', '{}', '{}', " . self::NANO . ')',
        ));
    }

    private function deepest(\Throwable $e): string
    {
        $message = $e->getMessage();
        $previous = $e->getPrevious();
        while ($previous instanceof \Throwable) {
            $message .= ' | ' . $previous->getMessage();
            $previous = $previous->getPrevious();
        }

        return $message;
    }

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

/**
 * Event bus fixture with a runtime-flippable failure switch — drives the
 * dead-letter → requeue → deliver cycle without a live dispatcher.
 *
 * @internal
 */
final class FlippableBus implements EventBusInterface
{
    public function __construct(public bool $fail) {}

    #[\Override]
    public function dispatch(object $event): object
    {
        if ($this->fail && $event instanceof OutboxMessage) {
            throw new \RuntimeException('downstream outage');
        }

        return $event;
    }

    #[\Override]
    public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

    #[\Override]
    public function subscribe(EventSubscriberInterface $subscriber): void {}

    #[\Override]
    public function dispatchWithContext(object $event, EventContext $context): object
    {
        return $this->dispatch($event);
    }

    /**
     * @return list<EventRegistration>
     */
    #[\Override]
    public function registrations(): array
    {
        return [];
    }

    #[\Override]
    public function freeze(): void {}

    /**
     * Extra provider surface (mirror of the legacy anonymous fixture) —
     * not part of EventBusInterface, kept for drop-in interchangeability.
     *
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        return [];
    }
}
