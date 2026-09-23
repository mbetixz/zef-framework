<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;

/**
 * Bridges committed {@see StoredEvent}s into the transactional outbox.
 *
 * Each stored event becomes one outbox entry whose messageType is the
 * event type and whose metadata is enriched with the stream coordinates
 * (eventId, aggregateType, aggregateId, version) — the system keys win over
 * user-supplied metadata so consumers can always trace an entry back to its
 * event.
 *
 * When the recorder's outbox shares a {@see ConnectionInterface} with the
 * event store (PDO adapters) and the aggregate repository wraps persist in
 * a transaction, enqueue + append commit atomically — the essence of the
 * transactional outbox pattern.
 */
final readonly class OutboxRecorder
{
    /**
     * @param OutboxStoreInterface      $outbox     outbox port
     * @param null|ConnectionInterface  $connection when the outbox is PDO-backed, the shared connection;
     *                                              the record() call then joins any ambient transaction
     *                                              instead of opening its own
     */
    public function __construct(
        private OutboxStoreInterface $outbox,
        private ?ConnectionInterface $connection = null,
    ) {}

    /**
     * @param list<StoredEvent> $events committed events, in append order
     *
     * @return list<OutboxEntry> the created entries, aligned with $events
     */
    /**
     * @param list<StoredEvent> $events committed events, in append order
     *
     * @return list<OutboxEntry> the created entries, aligned with $events
     */
    public function record(array $events): array
    {
        if ($events === []) {
            return [];
        }
        $work = fn (): array => $this->enqueueAll($events);
        if ($this->connection instanceof ConnectionInterface) {
            $entries = $this->connection->transaction($work);
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new EventSourcingException('Outbox record transaction returned an unexpected shape.');
            }

            return $entries;
        }

        return $this->enqueueAll($events);
    }

    /**
     * Enrichment applied on top of the event's own metadata.
     *
     * @return array<string, mixed>
     */
    public static function systemMetadata(StoredEvent $event): array
    {
        return [
            'eventId' => $event->eventId,
            'aggregateType' => $event->aggregateType,
            'aggregateId' => $event->aggregateId,
            'version' => $event->version,
            'globalSequence' => $event->globalSequence,
        ];
    }

    /**
     * @param list<StoredEvent> $events
     *
     * @return list<OutboxEntry>
     */
    private function enqueueAll(array $events): array
    {
        $entries = [];
        foreach ($events as $event) {
            $entries[] = $this->outbox->enqueue(
                $event->eventType,
                $event->payload,
                array_merge($event->metadata, self::systemMetadata($event)),
            );
        }

        return $entries;
    }
}
