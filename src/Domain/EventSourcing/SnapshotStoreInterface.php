<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Outbound port for aggregate snapshot storage.
 *
 * Snapshots are keyed by (aggregateType, aggregateId). Save semantics
 * (v2.23.0): a snapshot may never move BACKWARDS — implementations MUST
 * reject (no-op) a save whose version is strictly older than the stored
 * one, because a concurrent writer may have already persisted a newer
 * snapshot between this aggregate's load and save. Saving the same version
 * again is allowed (idempotent refresh with a fresher state payload).
 */
interface SnapshotStoreInterface
{
    public function save(Snapshot $snapshot): void;

    public function load(string $aggregateType, string $aggregateId): ?Snapshot;

    /**
     * @return bool true when a snapshot existed and was removed
     */
    public function delete(string $aggregateType, string $aggregateId): bool;
}
