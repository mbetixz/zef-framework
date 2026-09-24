<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: orchestration services)
 * Added in v2.22.0 (Transaction orchestration & UoW-lite).
 */

namespace Zef\Framework\Database;

use Zef\Framework\CQRS\TransactionalCommandBus;

/**
 * UoW-lite: a deferred-write queue with transactional flush semantics.
 *
 * This is deliberately NOT a full Unit of Work — the framework has no ORM,
 * so there are no entities to track. What CQRS handlers actually need is a
 * place to COLLECT the writes a command produces (via {@see QueryBuilder}
 * or raw {@see SqlQuery}) and execute them ATOMICALLY as part of the
 * command's transaction, in FIFO order, exactly once:
 *
 * - handlers call {@see record()} / {@see recordQuery()} during dispatch
 *   (or anywhere in the scope);
 * - the {@see TransactionalCommandBus} flushes the
 *   queue on the transaction's connection BEFORE the commit — a flush
 *   failure rolls the whole command back;
 * - standalone callers flush themselves, inside their own transaction or
 *   in auto-commit mode (each operation then stands alone).
 *
 * Ordering & safety contract:
 * - operations run strictly FIFO on ONE connection, sequentially;
 * - the queue is cleared BEFORE execution starts: a failed operation is
 *   never retried from this queue (the surrounding transaction rollback
 *   is the failure story; a retried command re-records fresh operations);
 * - {@see flush()} re-entry is refused loudly — an operation that flushes
 *   mid-flush has ambiguous ordering and is a caller bug;
 * - recording DURING a flush is likewise refused for the same reason.
 */
final class UnitOfWork
{
    /** @var list<callable(ConnectionInterface): void> */
    private array $operations = [];

    private bool $flushing = false;

    /**
     * Queue a deferred operation; it receives the flush connection.
     *
     * @param callable(ConnectionInterface): void $operation
     */
    public function record(callable $operation): void
    {
        if ($this->flushing) {
            throw new TransactionException('Cannot record operations while the UnitOfWork is flushing.');
        }
        $this->operations[] = $operation;
    }

    /**
     * Queue a single write as a prepared statement execution.
     */
    public function recordQuery(SqlQuery $query): void
    {
        $this->record(static function (ConnectionInterface $conn) use ($query): void {
            $conn->execute($query);
        });
    }

    /**
     * Execute every queued operation FIFO on $connection and clear the
     * queue. Returns the number of operations executed.
     */
    public function flush(ConnectionInterface $connection): int
    {
        if ($this->flushing) {
            throw new TransactionException('UnitOfWork::flush() re-entered while flushing.');
        }
        $operations = $this->operations;
        if ($operations === []) {
            return 0;
        }
        $this->operations = [];
        $this->flushing = true;

        try {
            $executed = 0;
            foreach ($operations as $operation) {
                $operation($connection);
                ++$executed;
            }
        } finally {
            $this->flushing = false;
        }

        return $executed;
    }

    /**
     * Number of operations waiting for the next flush.
     */
    public function pending(): int
    {
        return count($this->operations);
    }

    /**
     * Drop every queued operation without executing it (explicit abort).
     * Returns the number of operations discarded.
     */
    public function discard(): int
    {
        $discarded = count($this->operations);
        $this->operations = [];

        return $discarded;
    }
}
