<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.19.0 — Event Sourcing in-memory adapters: streams, concurrency,
 * timeline paging, snapshots, checkpoints, outbox FIFO + retry states.
 *
 * @internal
 */
final class EventSourcingStoresTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    // -------------------------------------------------- InMemoryEventStore

    public function testAppendAssignsVersionsAndGlobalSequence(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $created = $store->appendToStream('type', 'a', 0, $this->pending('e1'), $this->pending('e2', ['k' => 'v']));
        self::assertCount(2, $created);
        self::assertSame(1, $created[0]->version);
        self::assertSame(2, $created[1]->version);
        self::assertSame(1, $created[0]->globalSequence);
        self::assertSame(2, $created[1]->globalSequence);
        self::assertSame(self::NANO, $created[0]->recordedAtUnixNano);
        self::assertSame('e2', $created[1]->eventType);
        self::assertSame(['k' => 'v'], $created[1]->payload);

        $second = $store->appendToStream('type', 'a', 2, $this->pending('e3'));
        self::assertSame(3, $second[0]->version);
        self::assertSame(3, $second[0]->globalSequence);

        // Distinct stream in the same type keeps its own versioning.
        $other = $store->appendToStream('type', 'b', 0, $this->pending('e1'));
        self::assertSame(1, $other[0]->version);
        self::assertSame(4, $other[0]->globalSequence);
        self::assertSame(4, $store->countEvents());
        self::assertSame(4, $store->lastGlobalSequence());
    }

    public function testAppendGuardsAndConcurrency(): void
    {
        $store = new InMemoryEventStore();

        try {
            $store->appendToStream('bad type!', 'a', 0, $this->pending('e'));
            self::fail('bad aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            $store->appendToStream('type', 'a', -1, $this->pending('e'));
            self::fail('negative expected version must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Expected version must be >= 0 (got -1).', $e->getMessage());
        }

        try {
            $store->appendToStream('type', 'a', 0);
            self::fail('empty append must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('appendToStream() requires at least one event.', $e->getMessage());
        }

        $store->appendToStream('type', 'a', 0, $this->pending('e1'));
        $store->appendToStream('type', 'a', 1, $this->pending('e2'));

        try {
            $store->appendToStream('type', 'a', 0, $this->pending('e3'));
            self::fail('stale expected version must fail');
        } catch (ConcurrencyException $e) {
            self::assertSame(0, $e->expectedVersion());
            self::assertSame(2, $e->actualVersion());
        }

        try {
            $store->appendToStream('type', 'a', 3, $this->pending('e3'));
            self::fail('future expected version must fail');
        } catch (ConcurrencyException $e) {
            self::assertSame(3, $e->expectedVersion());
            self::assertSame(2, $e->actualVersion());
        }
        // Failed appends must not leave partial state.
        self::assertSame(2, $store->countEvents());
        self::assertSame(2, $store->lastGlobalSequence());
    }

    public function testLoadStreamReturnsEmptyForUnknown(): void
    {
        $store = new InMemoryEventStore();
        self::assertSame([], $store->loadStream('type', 'missing'));
        $store->appendToStream('type', 'a', 0, $this->pending('e1'));
        $stream = $store->loadStream('type', 'a');
        self::assertCount(1, $stream);
        self::assertInstanceOf(StoredEvent::class, $stream[0]);
        self::assertSame([], $store->loadStream('other-type', 'a'));
    }

    public function testStreamAllPagingAndValidation(): void
    {
        $store = new InMemoryEventStore();
        $store->appendToStream('t', 'a', 0, $this->pending('e1'), $this->pending('e2'), $this->pending('e3'));
        $store->appendToStream('t', 'b', 0, $this->pending('e4'));

        self::assertCount(4, $store->streamAll());
        self::assertCount(4, $store->streamAll(1));
        self::assertCount(3, $store->streamAll(2));
        self::assertCount(0, $store->streamAll(5));
        $page = $store->streamAll(2, 2);
        self::assertCount(2, $page);
        self::assertSame(2, $page[0]->globalSequence);
        self::assertSame(3, $page[1]->globalSequence);

        try {
            $store->streamAll(0);
            self::fail('from 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('fromGlobalSequence must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            $store->streamAll(1, 0);
            self::fail('limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('limit must be >= 1 when provided (got 0).', $e->getMessage());
        }
    }

    public function testDefaultClockIsRealtimeNanoseconds(): void
    {
        $store = new InMemoryEventStore();
        [$event] = $store->appendToStream('t', 'a', 0, $this->pending('e'));
        $before = (int) (microtime(true) * 1_000_000_000);
        self::assertLessThanOrEqual($before, $event->recordedAtUnixNano);
        self::assertGreaterThan($before - 60_000_000_000, $event->recordedAtUnixNano);
    }

    // -------------------------------------------------- InMemorySnapshotStore

    public function testSnapshotStoreLatestWinsAndDelete(): void
    {
        $store = new InMemorySnapshotStore();
        self::assertNull($store->load('t', 'a'));
        self::assertFalse($store->delete('t', 'a'));

        $store->save(new Snapshot('t', 'a', 5, ['v' => 5], 1));
        $store->save(new Snapshot('t', 'a', 10, ['v' => 10], 2));
        $loaded = $store->load('t', 'a');
        self::assertNotNull($loaded);
        self::assertSame(10, $loaded->version);
        self::assertSame(['v' => 10], $loaded->state);
        self::assertSame(2, $loaded->createdAtUnixNano);

        $store->save(new Snapshot('t', 'b', 1, [], 3));
        self::assertSame(2, $store->count());
        self::assertTrue($store->delete('t', 'a'));
        self::assertNull($store->load('t', 'a'));
        self::assertSame(1, $store->count());
    }

    // -------------------------------------------------- InMemoryCheckpointStore

    public function testCheckpointStoreGuards(): void
    {
        $store = new InMemoryCheckpointStore();
        self::assertNull($store->get('p'));
        $store->set('p', 41);
        self::assertSame(41, $store->get('p'));
        $store->set('p', 42);
        self::assertSame(42, $store->get('p'));
        self::assertSame(1, $store->count());

        try {
            $store->set('p', 0);
            self::fail('checkpoint 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('global sequence must be >= 1 (got 0)', $e->getMessage());
        }

        try {
            $store->set("bad\nid", 1);
            self::fail('bad projection id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid projection id', $e->getMessage());
        }
    }

    // -------------------------------------------------- InMemoryOutbox

    public function testOutboxEnqueueDueOrderAndLimits(): void
    {
        $now = self::NANO;
        $ticks = 0;
        $outbox = new InMemoryOutbox(static function () use (&$ticks, $now): int {
            ++$ticks;

            return $now + $ticks;
        });
        $first = $outbox->enqueue('m1', ['a' => 1], ['meta' => 1]);
        $second = $outbox->enqueue('m2', [], []);
        self::assertSame(0, $first->attempts);
        self::assertTrue($first->isPending());
        self::assertNull($first->lastError);
        self::assertSame($now + 1, $first->createdAtUnixNano);
        self::assertSame($now + 1, $first->nextAttemptAtUnixNano);
        self::assertSame($now + 2, $second->createdAtUnixNano);
        self::assertSame(2, $outbox->count());
        self::assertSame(2, $outbox->countPending());

        // Due before now: nothing (createdAt is in the future).
        self::assertSame([], $outbox->due(10, $now));
        $due = $outbox->due(10, $now + 10);
        self::assertCount(2, $due);
        self::assertSame('m1', $due[0]->messageType);
        self::assertSame('m2', $due[1]->messageType);

        // Limit trims the FIFO head.
        self::assertCount(1, $outbox->due(1, $now + 10));

        try {
            $outbox->due(0, $now);
            self::fail('due limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('due() limit must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            $outbox->failed(0);
            self::fail('failed limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('failed() limit must be >= 1 (got 0).', $e->getMessage());
        }
    }

    public function testOutboxMarkLifecycle(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('m', [], []);

        try {
            $outbox->markProcessed('missing');
            self::fail('unknown id must fail');
        } catch (EventSourcingException $e) {
            self::assertSame("Unknown outbox entry 'missing'.", $e->getMessage());
        }

        $outbox->markFailed($entry->id, 'first boom', self::NANO + 5);
        $retried = $outbox->due(10, self::NANO + 5)[0];
        self::assertSame(1, $retried->attempts);
        self::assertSame('first boom', $retried->lastError);
        self::assertSame(self::NANO + 5, $retried->nextAttemptAtUnixNano);
        self::assertTrue($retried->isPending());
        self::assertSame([], $outbox->due(10, self::NANO + 4), 'entry before retry deadline must not be due');

        $outbox->markFailed($entry->id, 'second boom', self::NANO + 9);
        $again = $outbox->due(10, self::NANO + 9)[0];
        self::assertSame(2, $again->attempts);

        $outbox->markProcessed($entry->id);
        self::assertSame([], $outbox->due(10, PHP_INT_MAX), 'processed entries are never due');
        self::assertSame(0, $outbox->countPending());
        self::assertSame([], $outbox->failed(10));

        $dead = $outbox->enqueue('m2', [], []);
        $outbox->markDead($dead->id, 'final boom');
        $letters = $outbox->failed(10);
        self::assertCount(1, $letters);
        self::assertSame('final boom', $letters[0]->lastError);
        self::assertSame(1, $letters[0]->attempts);
        self::assertTrue($letters[0]->isFailed());
        self::assertSame([], $outbox->due(10, PHP_INT_MAX), 'dead entries are never due');
        self::assertSame(0, $outbox->countPending());
    }

    public function testOutboxDueTieBreakByIdAndNowFallback(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $a = $outbox->enqueue('m', [], []);
        $b = $outbox->enqueue('m', [], []);
        // Same createdAt (fixed clock): stable (createdAt, id) ordering.
        $ids = array_map(static fn (OutboxEntry $e): string => $e->id, $outbox->due(10, self::NANO));
        sort($ids);
        $got = array_map(static fn (OutboxEntry $e): string => $e->id, $outbox->due(10, self::NANO));
        self::assertSame($ids, $got, 'ties must be resolved by id ascending');
        self::assertCount(2, $got);
        self::assertNotSame($a->id, $b->id);
    }

    /**
     * @param array<mixed> $payload
     */
    private function pending(string $type, array $payload = []): PendingEvent
    {
        return new PendingEvent($type, $payload);
    }
}
