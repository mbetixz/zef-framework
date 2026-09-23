<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * An event recorded on an aggregate but not yet appended to an event store.
 *
 * The store assigns identity (event id), the stream-local version and the
 * global sequence at append time — a pending event deliberately carries no
 * version of its own so a stale pending list can never claim a version the
 * store has not confirmed.
 *
 * @see AggregateRoot::recordEvent()
 */
final readonly class PendingEvent
{
    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public function __construct(
        public string $eventType,
        public array $payload = [],
        public array $metadata = [],
    ) {
        EventGrammar::assertEventType($eventType);
        EventGrammar::assertPayload($payload, 'Pending event payload');
        EventGrammar::assertPayload($metadata, 'Pending event metadata');
    }
}
