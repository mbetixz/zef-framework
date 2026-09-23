<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Outbound port for projection checkpoints.
 *
 * A checkpoint records the highest `globalSequence` a projection has fully
 * processed. Checkpoint values are only ever written after an event has
 * been handled, so projections are at-least-once: on failure the checkpoint
 * stays behind and the event is re-delivered on the next run.
 */
interface CheckpointStoreInterface
{
    /**
     * @return null|int the stored global sequence, or null when the
     *                  projection has never processed an event
     */
    public function get(string $projectionId): ?int;

    /**
     * @param int $globalSequence >= 1, only written after the event was handled
     */
    public function set(string $projectionId, int $globalSequence): void;
}
