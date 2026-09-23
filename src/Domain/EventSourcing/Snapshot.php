<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * A point-in-time materialisation of an aggregate at a specific stream
 * version, used by {@see SnapshotStoreInterface} implementations to short-
 * circuit event replay.
 *
 * `state` is the payload produced by {@see AggregateRoot::snapshotState()}
 * and consumed by the aggregate's `restoreFromSnapshot()` factory — its
 * shape is entirely up to the aggregate class.
 */
final readonly class Snapshot
{
    /**
     * @param array<mixed> $state
     */
    public function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public int $version,
        public array $state,
        public int $createdAtUnixNano = 0,
    ) {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        EventGrammar::assertVersion($version);
        EventGrammar::assertPayload($state, 'Snapshot state');
        EventGrammar::assertUnixNano($createdAtUnixNano, 'createdAtUnixNano');
    }
}
