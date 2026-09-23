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
 * Snapshots are keyed by (aggregateType, aggregateId); a save always
 * replaces any previously stored snapshot for the same identity — the
 * latest snapshot wins.
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
