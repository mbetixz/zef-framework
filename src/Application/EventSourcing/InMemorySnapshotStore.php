<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * In-memory {@see SnapshotStoreInterface} — latest snapshot wins per
 * (aggregateType, aggregateId).
 *
 * v2.23.0 hardening: {@see save()} refuses to regress — a snapshot older
 * than the stored one is discarded (the port's concurrency contract).
 */
final class InMemorySnapshotStore implements SnapshotStoreInterface
{
    /** @var array<string, array<string, Snapshot>> */
    private array $snapshots = [];

    #[\Override]
    public function save(Snapshot $snapshot): void
    {
        $existing = $this->snapshots[$snapshot->aggregateType][$snapshot->aggregateId] ?? null;
        if ($existing instanceof Snapshot && $existing->version > $snapshot->version) {
            return; // a concurrent writer already saved a newer snapshot
        }
        $this->snapshots[$snapshot->aggregateType][$snapshot->aggregateId] = $snapshot;
    }

    #[\Override]
    public function load(string $aggregateType, string $aggregateId): ?Snapshot
    {
        return $this->snapshots[$aggregateType][$aggregateId] ?? null;
    }

    #[\Override]
    public function delete(string $aggregateType, string $aggregateId): bool
    {
        if (!isset($this->snapshots[$aggregateType][$aggregateId])) {
            return false;
        }
        unset($this->snapshots[$aggregateType][$aggregateId]);

        return true;
    }

    /**
     * Test/ops helper: total number of stored snapshots.
     */
    public function count(): int
    {
        return array_sum(array_map(count(...), $this->snapshots));
    }
}
