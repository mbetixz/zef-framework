<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: orchestration services)
 * Added in v2.22.0 (Transaction orchestration & UoW-lite).
 */

namespace Zef\Framework\Database;

/**
 * Default {@see TransactionManagerInterface} implementation over a single
 * {@see ConnectionInterface}.
 *
 * The transaction mechanics themselves (BEGIN / SAVEPOINT nesting,
 * isolation, rollback-on-throw) are delegated to the connection primitive —
 * this class owns ONLY the managed-scope bookkeeping and the after-commit
 * hook lifecycle:
 *
 * - `$scopeDepth` counts open managed scopes (raw connection transactions
 *   are invisible to it);
 * - `$hooks` accumulates FIFO across the whole nested scope tree;
 * - after the OUTERMOST managed commit, `$flushing` turns immediate-mode
 *   ON while the queue drains, so hooks enqueued by hooks run inline
 *   instead of re-queueing; on outermost failure the queue is discarded.
 *
 * Instances are stateful and NOT shareable across concurrent dispatches
 * (PHP request scope) — wire one per bus/worker.
 */
final class TransactionManager implements TransactionManagerInterface
{
    /** @var list<callable(): void> */
    private array $hooks = [];

    private int $scopeDepth = 0;

    private bool $flushing = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    #[\Override]
    public function withTransaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        $outermost = $this->scopeDepth === 0;
        ++$this->scopeDepth;

        try {
            $result = $this->connection->transaction(static fn (ConnectionInterface $conn): mixed => $fn($conn), $isolation);
        } catch (\Throwable $e) {
            --$this->scopeDepth;
            if ($outermost) {
                $this->hooks = [];
            }

            throw $e;
        }
        --$this->scopeDepth;

        if ($outermost) {
            $this->drainHooks();
        }

        return $result;
    }

    #[\Override]
    public function afterCommit(callable $hook): void
    {
        if ($this->flushing || !$this->inTransaction()) {
            $hook();

            return;
        }
        $this->hooks[] = $hook;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->scopeDepth > 0;
    }

    #[\Override]
    public function level(): int
    {
        return $this->scopeDepth;
    }

    /**
     * Drain the hook queue in FIFO order after the outermost commit.
     * The queue is cleared BEFORE draining so a hook failure can never
     * replay already-executed hooks on a later scope; `$flushing` makes
     * hooks registered during the drain run inline.
     */
    private function drainHooks(): void
    {
        $pending = $this->hooks;
        $this->hooks = [];
        if ($pending === []) {
            return;
        }
        $this->flushing = true;

        try {
            foreach ($pending as $hook) {
                $hook();
            }
        } finally {
            $this->flushing = false;
        }
    }
}
