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
     *
     * v2.22.0 contract (UNCHANGED): the queue is cleared BEFORE execution
     * starts — a failed operation is never retried from this queue. The
     * surrounding transaction rollback is the failure story; a retried
     * command re-records fresh operations.
     *
     * For opt-in retry of transient DB failures see
     * {@see flushRetrying()} (v2.22.1, issue #65 item 2).
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
     * Flush with opt-in retry for transient DB failures (v2.22.1,
     * issue #65 item 2).
     *
     * Unlike {@see flush()}, this method preserves the queued operations
     * across retry attempts — the snapshot is replayed each attempt
     * until either the flush succeeds or the policy is exhausted. The
     * queue is cleared ONLY on a successful flush; when a throwable
     * escapes this method (non-retryable, or the policy exhausted), the
     * queue is deliberately left populated for the caller to discard or
     * explicitly replay after recovering the surrounding transaction.
     *
     * Contract (see docs/TRANSACTION-HOOKS.md §"UoW retry strategy"):
     * - Only the FLUSH phase is retried — never the command handler
     *   body. Side effects produced during dispatch are NOT re-run.
     * - Each attempt opens a transaction (a savepoint when nested) and
     *   rolls back ALL its writes before considering a retry. Earlier
     *   writes in the caller's transaction are preserved.
     * - Begin, rollback and commit/release failures propagate without
     *   retry. A driver that destroys the whole transaction also destroys
     *   its savepoints: failed rollback stops replay, since the handler's
     *   earlier writes cannot be recovered by retrying the flush alone.
     * - Operations must use transactional writes on this connection;
     *   external effects and implicit-commit DDL cannot be rolled back.
     * - The policy decides retryability via
     *   {@see UnitOfWorkRetryPolicy::isRetryable()}; non-retryable
     *   throwables propagate immediately.
     * - On exhaustion the last throwable propagates.
     *
     * @return int number of operations executed by the successful attempt
     */
    public function flushRetrying(
        ConnectionInterface $connection,
        UnitOfWorkRetryPolicy $policy,
    ): int {
        if ($this->flushing) {
            throw new TransactionException('UnitOfWork::flushRetrying() re-entered while flushing.');
        }
        $operations = $this->operations;
        if ($operations === []) {
            return 0;
        }
        $this->flushing = true;

        try {
            $attempt = 0;
            while (true) {
                ++$attempt;
                $connection->beginTransaction();

                try {
                    $executed = 0;
                    foreach ($operations as $operation) {
                        $operation($connection);
                        ++$executed;
                    }
                } catch (\Throwable $e) {
                    // Never replay unless the entire failed attempt was undone.
                    // A rollback failure must escape even under a broad policy.
                    $connection->rollBack();
                    if (!$policy->isRetryable($e) || !$policy->shouldRetry($attempt)) {
                        throw $e;
                    }
                    $delayMs = $policy->delayMs($attempt);
                    usleep($delayMs * 1_000);

                    continue;
                }

                // Commit/release is outside the retry catch: its outcome may
                // be uncertain, so replay could duplicate committed writes.
                $connection->commit();
                $this->operations = [];

                return $executed;
            }
        } finally {
            $this->flushing = false;
        }
        // Unreachable — the loop either returns or throws.
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
