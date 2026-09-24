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
 */
final readonly class TransactionalCommandBus implements CommandBusInterface
{
    public function __construct(
        private CommandBusInterface $inner,
        private TransactionManagerInterface $transactions,
        private ?UnitOfWork $unitOfWork = null,
        private ?IsolationLevel $isolation = null,
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
            $this->unitOfWork?->flush($connection);

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
}
