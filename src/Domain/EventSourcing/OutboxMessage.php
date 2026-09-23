<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Event\EventBusInterface;

/**
 * The event object dispatched on the {@see EventBusInterface}
 * by the outbox relay — one {@see OutboxEntry} per dispatch.
 *
 * Listeners register for `OutboxMessage::class` and switch on
 * `$message->entry->messageType` (the originating stored-event type) plus
 * the JSON-round-tripped `$message->entry->payload`.
 *
 * Delivery is at-least-once: relay failures keep the entry pending until
 * the retry budget is exhausted, so consumers must stay idempotent.
 */
final readonly class OutboxMessage
{
    public function __construct(
        public OutboxEntry $entry,
    ) {}
}
