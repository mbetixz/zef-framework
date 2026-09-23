<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Raised by {@see AggregateRepository::findOrFail()}
 * when neither a snapshot nor any recorded event exists for the requested
 * aggregate identity.
 */
final class AggregateNotFoundException extends EventSourcingException
{
    public function __construct(
        private readonly string $aggregateClass,
        private readonly string $aggregateId,
    ) {
        parent::__construct("Aggregate {$aggregateClass} '{$aggregateId}' was not found.");
    }

    public function aggregateClass(): string
    {
        return $this->aggregateClass;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }
}
