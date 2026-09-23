<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * In-memory {@see OutboxStoreInterface} — FIFO by (createdAt, id).
 */
final class InMemoryOutbox implements OutboxStoreInterface
{
    /** @var array<string, OutboxEntry> keyed by entry id, insertion-ordered */
    private array $entries = [];

    /**
     * @param null|(\Closure(): int) $clock createdAt source (default: realtime nanoseconds)
     */
    public function __construct(private readonly ?\Closure $clock = null) {}

    #[\Override]
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
    {
        $clock = $this->clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
        $now = $clock();
        $entry = new OutboxEntry(
            id: bin2hex(random_bytes(16)),
            messageType: $messageType,
            payload: $payload,
            metadata: $metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $now,
            lastError: null,
            createdAtUnixNano: $now,
        );
        $this->entries[$entry->id] = $entry;

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }
        $now = $nowUnixNano ?? ($this->clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000))();
        $eligible = [];
        foreach ($this->entries as $entry) {
            if ($entry->isPending() && $entry->nextAttemptAtUnixNano <= $now) {
                $eligible[] = $entry;
            }
        }
        usort($eligible, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        return \array_slice($eligible, 0, $limit);
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts,
            status: OutboxEntry::STATUS_PROCESSED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $entry->lastError,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $retryAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_FAILED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }
        $dead = [];
        foreach ($this->entries as $entry) {
            if ($entry->isFailed()) {
                $dead[] = $entry;
            }
        }
        usort($dead, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        return \array_slice($dead, 0, $limit);
    }

    #[\Override]
    public function countPending(): int
    {
        $pending = 0;
        foreach ($this->entries as $entry) {
            if ($entry->isPending()) {
                ++$pending;
            }
        }

        return $pending;
    }

    /**
     * Test/ops helper: total number of entries in every state.
     */
    public function count(): int
    {
        return \count($this->entries);
    }

    /** @param \Closure(OutboxEntry): OutboxEntry $fn */
    private function mutate(string $id, \Closure $fn): OutboxEntry
    {
        $entry = $this->entries[$id] ?? null;
        if (!$entry instanceof OutboxEntry) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }

        return $fn($entry);
    }
}
