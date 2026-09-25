<?php

declare(strict_types=1);

/*
 * ZEF Framework — CQRS (Application layer: in-process orchestration)
 * Added in v2.22.0 (Transaction orchestration & UoW-lite).
 */

namespace Zef\Framework\CQRS;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\TransactionManagerInterface;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * Transactional decorator over a {@see CommandBusInterface}.
 *
 * Every dispatch runs inside a managed transaction
 * ({@see TransactionManagerInterface::withTransaction()}):
 *
 * - the handler chain executes, then the optional {@see UnitOfWork}
 *   flushes its deferred writes ON THE SAME CONNECTION — both succeed or
 *   the whole command rolls back;
 * - when the inner bus was wired with the SAME TransactionManager, its
 *   event fan-out is queued via afterCommit() and fires only after the
 *   commit — a rolled-back command emits nothing, and listeners observe
 *   committed data;
 * - nested dispatches (a handler dispatching another command through the
 *   same decorator) become savepoints — the outermost command owns the
 *   commit.
 *
 * Ordering with idempotency: the inner bus caches the result before the
 * commit, so a replay never re-executes the handler; events still fire
 * only for the FIRST successful commit (the inner bus defers fan-out to
 * afterCommit hooks, which a replay never registers).
 *
 * v2.22.1 (issue #65, item 2 — UoW retry strategy): when an optional
 * {@see UnitOfWorkRetryPolicy} is injected, the FLUSH phase is wrapped in
 * a retry loop for transient DB failures (deadlock, lock-wait-timeout,
 * serialization-failure). Only the flush retries — never the handler
 * body. Default `null` preserves the v2.22.0 no-retry behaviour.
 *
 * @see docs/TRANSACTION-HOOKS.md §"UoW retry strategy" for the full
 *      contract and rationale.
 */
final readonly class TransactionalCommandBus implements CommandBusInterface
{
    public function __construct(
        private CommandBusInterface $inner,
        private TransactionManagerInterface $transactions,
        private ?UnitOfWork $unitOfWork = null,
        private ?IsolationLevel $isolation = null,
        private ?UnitOfWorkRetryPolicy $retryPolicy = null,
    ) {}

    #[\Override]
    public function register(string $commandClass, callable|CommandHandlerInterface $handler): void
    {
        $this->inner->register($commandClass, $handler);
    }

    #[\Override]
    public function use(CqrsMiddlewareInterface $middleware): void
    {
        $this->inner->use($middleware);
    }

    #[\Override]
    public function dispatch(object $command, ?CqrsContext $context = null): mixed
    {
        return $this->transactions->withTransaction(function (ConnectionInterface $connection) use ($command, $context): mixed {
            $result = $this->inner->dispatch($command, $context);
            $this->flushWithRetry($connection);

            return $result;
        }, $this->isolation);
    }

    #[\Override]
    public function freeze(): void
    {
        $this->inner->freeze();
    }

    #[\Override]
    public function isFrozen(): bool
    {
        return $this->inner->isFrozen();
    }

    /**
     * Flush the UoW queue, optionally retrying on transient failures.
     *
     * Contract (see docs/TRANSACTION-HOOKS.md §"UoW retry strategy"):
     * - When no {@see UnitOfWork} or no {@see UnitOfWorkRetryPolicy} is
     *   configured, this is a direct call to flush() — preserving the
     *   v2.22.0 single-shot behaviour (queue cleared before execution).
     * - When a retry policy is configured, delegates to
     *   {@see UnitOfWork::flushRetrying()} which preserves the queue
     *   snapshot across attempts. Only the FLUSH phase retries — never
     *   the command handler body.
     */
    private function flushWithRetry(ConnectionInterface $connection): void
    {
        if ($this->unitOfWork === null) {
            return;
        }
        if ($this->retryPolicy === null) {
            $this->unitOfWork->flush($connection);

            return;
        }
        $this->unitOfWork->flushRetrying($connection, $this->retryPolicy);
    }
}

