<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * A catch-up projection: a stateful read-model consumer of stored events.
 *
 * Projections declare the event types they care about via {@see handles()};
 * the {@see Projector} skips every other event
 * (while still advancing the projection's checkpoint past it).
 *
 * Because the checkpoint is only advanced after {@see handle()} returns
 * successfully, projections MUST tolerate duplicate delivery of the same
 * stored event (at-least-once semantics).
 */
interface ProjectionInterface
{
    /**
     * Stable projection identity used as the checkpoint key
     * (1..128 bytes of [A-Za-z0-9._:-]).
     */
    public function projectionId(): string;

    /**
     * @return list<string> event types this projection consumes
     */
    public function handles(): array;

    public function handle(StoredEvent $event): void;
}
