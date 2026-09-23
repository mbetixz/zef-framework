<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlExpression;
use Zef\Framework\Database\SqlQuery;

/**
 * PDO-backed {@see OutboxStoreInterface} built on the Database Core port.
 *
 * - `due()` selects pending entries whose retry timestamp has passed,
 *   FIFO by (created_at, id) — deterministic delivery order;
 * - `markFailed()` bumps attempts with `attempts + 1` expressed as a raw
 *   {@see SqlExpression} (read-then-write would race under concurrent relays);
 * - shares the ambient-transaction contract with PdoEventStore: enqueue
 *   joins an open transaction, so repository.persist commits events and
 * outbox entries atomically over one connection.
 */
final class PdoOutbox implements OutboxStoreInterface
{
    private const array COLUMNS = ['id', 'message_type', 'payload', 'metadata', 'attempts', 'status', 'next_attempt_at', 'last_error', 'created_at'];
    private readonly string $table;

    /** @var (\Closure(): int) */
    private readonly \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port (shared connection enables atomic persist)
     * @param string                 $table      outbox table name
     * @param null|(\Closure(): int) $clock      createdAt/now source (default: realtime nanoseconds)
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        string $table = 'zef_outbox',
        ?\Closure $clock = null,
    ) {
        new QueryBuilder()->quoteIdentifier($table, 'table');
        $this->table = $table;
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Create the outbox table (portable DDL, safe to run repeatedly).
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ('
            . '"id" VARCHAR(64) NOT NULL, '
            . '"message_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"metadata" TEXT NOT NULL, '
            . '"status" VARCHAR(16) NOT NULL, '
            . '"attempts" INT NOT NULL, '
            . '"next_attempt_at" BIGINT NOT NULL, '
            . '"last_error" TEXT NULL, '
            . '"created_at" BIGINT NOT NULL, '
            . 'CONSTRAINT "uq_' . $this->table . '_id" UNIQUE ("id"))',
        ));
    }

    #[\Override]
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
    {
        $now = ($this->clock)();
        $entry = new OutboxEntry(
            id: bin2hex(random_bytes(16)),
            messageType: $messageType,
            payload: $payload,
            metadata: $metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $now,
            lastError: null,
            createdAtUnixNano: $now,
        );
        $this->connection->execute(
            QueryBuilder::table($this->table)->insert([
                'id' => $entry->id,
                'message_type' => $entry->messageType,
                'payload' => EventJson::encode($entry->payload, 'Outbox entry payload'),
                'metadata' => EventJson::encode($entry->metadata, 'Outbox entry metadata'),
                'status' => $entry->status,
                'attempts' => $entry->attempts,
                'next_attempt_at' => $entry->nextAttemptAtUnixNano,
                'last_error' => $entry->lastError,
                'created_at' => $entry->createdAtUnixNano,
            ])->build(),
        );

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();

        return array_map(
            $this->hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->updateById($id, ['status' => OutboxEntry::STATUS_PROCESSED]);
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PENDING,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'next_attempt_at' => $retryAtUnixNano,
            'last_error' => $error,
        ]);
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_FAILED,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'last_error' => $error,
        ]);
    }

    #[\Override]
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }

        return array_map(
            $this->hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_FAILED)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    #[\Override]
    public function countPending(): int
    {
        $row = $this->connection->fetchOne(
            QueryBuilder::table($this->table)
                ->aggregate('COUNT', '*')
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->build(),
        );
        $value = $row['aggregate'] ?? null;
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new EventSourcingException('countPending() returned no aggregate value.');
        }

        return RowCast::int($value);
    }

    /**
     * @param array<string, mixed> $pairs
     */
    private function updateById(string $id, array $pairs): void
    {
        $affected = $this->connection->execute(
            QueryBuilder::table($this->table)
                ->update($pairs)
                ->where('id', '=', $id)
                ->build(),
        );
        if ($affected === 0) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }
    }

    private function selectQb(): QueryBuilder
    {
        return QueryBuilder::table($this->table)->select(...self::COLUMNS);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxEntry
    {
        $lastError = $row['last_error'] ?? null;

        return new OutboxEntry(
            id: RowCast::string($row['id'] ?? null),
            messageType: RowCast::string($row['message_type'] ?? null),
            payload: EventJson::decode(RowCast::string($row['payload'] ?? null), 'outbox entry payload'),
            metadata: EventJson::decode(RowCast::string($row['metadata'] ?? null), 'outbox entry metadata'),
            attempts: RowCast::int($row['attempts'] ?? null),
            status: RowCast::string($row['status'] ?? null),
            nextAttemptAtUnixNano: RowCast::int($row['next_attempt_at'] ?? null),
            lastError: $lastError === null ? null : RowCast::string($lastError),
            createdAtUnixNano: RowCast::int($row['created_at'] ?? null),
        );
    }
}
