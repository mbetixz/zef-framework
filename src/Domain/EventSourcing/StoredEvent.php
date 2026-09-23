<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * An event as it was durably committed to an event store.
 *
 * Every field is store-assigned at append time:
 * - `eventId`       — 32 hex chars, unique per event;
 * - `version`       — 1-based position inside the aggregate stream;
 * - `globalSequence`— 1-based monotonic position across the whole store,
 *                     the projection/checkpoint currency.
 *
 * Instances are immutable; adapters construct them directly from rows and
 * nothing may ever mutate one after commit.
 */
final readonly class StoredEvent
{
    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public function __construct(
        public string $eventId,
        public string $aggregateType,
        public string $aggregateId,
        public int $version,
        public int $globalSequence,
        public string $eventType,
        public array $payload = [],
        public array $metadata = [],
        public int $recordedAtUnixNano = 0,
    ) {
        EventGrammar::assertEventId($eventId);
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        EventGrammar::assertVersion($version);
        EventGrammar::assertGlobalSequence($globalSequence);
        EventGrammar::assertEventType($eventType);
        EventGrammar::assertPayload($payload, 'Stored event payload');
        EventGrammar::assertPayload($metadata, 'Stored event metadata');
        EventGrammar::assertUnixNano($recordedAtUnixNano, 'recordedAtUnixNano');
    }
}
