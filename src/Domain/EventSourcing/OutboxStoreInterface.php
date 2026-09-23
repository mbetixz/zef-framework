<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Outbound port for transactional-outbox storage.
 *
 * Ordering contract for {@see due()} and {@see failed()}: entries are
 * returned ordered by (createdAt, id) ascending — deterministic, so relays
 * and tests can rely on FIFO delivery.
 *
 * Implementations SHOULD participate in an ambient transaction when one is
 * already open on the underlying connection, mirroring
 * {@see EventStoreInterface}, so event appends and outbox enqueues commit
 * atomically when both share one connection.
 */
interface OutboxStoreInterface
{
    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry;

    /**
     * Entries eligible for relay: status `pending` AND
     * `nextAttemptAt <= $nowUnixNano` (or the adapter's clock when null).
     *
     * @param int      $limit          >= 1
     * @param null|int $nowUnixNano    when null, the adapter's clock decides
     *
     * @return list<OutboxEntry> ordered by (createdAt, id) ascending
     */
    public function due(int $limit, ?int $nowUnixNano = null): array;

    /**
     * @throws EventSourcingException when the entry does not exist
     */
    public function markProcessed(string $id): void;

    /**
     * Record a relay failure: attempts is incremented, the entry stays
     * `pending` and becomes eligible again at $retryAtUnixNano.
     *
     * @throws EventSourcingException when the entry does not exist
     */
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void;

    /**
     * Record the final failure: status becomes `failed` (dead letter) and
     * attempts is incremented. The entry is never relayed again.
     *
     * @throws EventSourcingException when the entry does not exist
     */
    public function markDead(string $id, string $error): void;

    /**
     * Dead letters (status `failed`), ordered by (createdAt, id) ascending.
     *
     * @param int $limit >= 1
     *
     * @return list<OutboxEntry>
     */
    public function failed(int $limit): array;

    public function countPending(): int;
}
