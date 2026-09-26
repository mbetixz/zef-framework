<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\TransactionManagerInterface;
use Zef\Framework\Event\EventBusInterface;

final class CommandBus implements CommandBusInterface
{
    use CqrsBusTrait;

    public function __construct(
        private readonly ?IdempotencyStoreInterface $idempotencyStore = null,
        private readonly int $idempotencyTtlSeconds = 3600,
        private readonly ?EventBusInterface $eventBus = null,
        private readonly ?TransactionManagerInterface $transactions = null,
    ) {
        if ($idempotencyTtlSeconds < 1) {
            throw new \InvalidArgumentException('CQRS idempotency TTL must be positive.');
        }
    }

    #[\Override]
    public function register(string $commandClass, callable|CommandHandlerInterface $handler): void
    {
        $this->assertMutable();
        $this->validateMessageClass($commandClass, 'command');
        if (isset($this->handlers[$commandClass])) {
            throw new CqrsHandlerConflictException("Command handler already registered for '{$commandClass}'.");
        }
        $this->handlers[$commandClass] = $handler instanceof CommandHandlerInterface
            ? $handler(...)
            : $handler;
    }

    #[\Override]
    public function use(CqrsMiddlewareInterface $middleware): void
    {
        $this->assertMutable();
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function dispatch(object $command, ?CqrsContext $context = null): mixed
    {
        return $this->dispatchInScope($command, $context);
    }

    /**
     * Keep the store's entire remember operation around handler, flush and
     * commit. A store's producer lock therefore also covers the transaction.
     *
     * @internal used by TransactionalCommandBus
     *
     * @param \Closure(ConnectionInterface): void $flush
     */
    public function dispatchTransactional(
        object $command,
        ?CqrsContext $context,
        TransactionManagerInterface $transactions,
        \Closure $flush,
        ?IsolationLevel $isolation = null,
    ): mixed {
        $hookFailure = null;
        $scope = static function (\Closure $execute) use ($transactions, $flush, $isolation, &$hookFailure): mixed {
            $committed = false;
            $result = null;

            try {
                return $transactions->withTransaction(
                    static function (ConnectionInterface $connection) use ($transactions, $execute, $flush, &$committed, &$result): mixed {
                        // Register before user hooks: their failures happen after
                        // the write succeeded and must not permit re-execution.
                        $transactions->afterCommit(static function () use (&$committed): void {
                            $committed = true;
                        });
                        $result = $execute();
                        $flush($connection);

                        return $result;
                    },
                    $isolation,
                );
            } catch (\Throwable $error) {
                if (!$committed) {
                    throw $error;
                }
                $hookFailure = $error;

                return $result;
            }
        };
        $result = $this->dispatchInScope($command, $context, $scope, $transactions);
        if ($hookFailure instanceof \Throwable) {
            throw $hookFailure;
        }

        return $result;
    }

    #[\Override]
    public function freeze(): void
    {
        $this->frozen = true;
    }

    #[\Override]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @param null|\Closure(\Closure(): mixed): mixed $scope */
    private function dispatchInScope(
        object $command,
        ?CqrsContext $context,
        ?\Closure $scope = null,
        ?TransactionManagerInterface $transactions = null,
    ): mixed {
        $context ??= CqrsContext::create();
        $transactions ??= $this->transactions;
        if ($context->idempotencyKey !== null
            && $this->idempotencyStore instanceof IdempotencyStoreInterface
            && $transactions instanceof TransactionManagerInterface
            && $transactions->inTransaction()
        ) {
            // remember() has no prepare/commit protocol. A nested savepoint
            // cannot publish a result safely before its owner commits.
            throw new \LogicException('Idempotent commands must own the outermost managed transaction.');
        }
        $pendingEvents = [];
        $execute = function () use ($command, $context, &$pendingEvents): mixed {
            $handler = $this->resolveHandler($command, 'command');
            $next = $this->buildChain($handler);
            $result = $next($command, $context);
            if ($result instanceof CqrsEventResult) {
                // Defer the event fan-out until AFTER the result is cached:
                // the handler already ran, so a listener failure must not
                // invalidate idempotency — with the fan-out inside the
                // producer, EventDispatchException skipped the cache write
                // and a client retry re-EXECUTED the command (double side
                // effects).
                $pendingEvents = $result->events;

                return $result->result;
            }

            return $result;
        };
        if ($scope instanceof \Closure) {
            $handlerExecution = $execute;
            $execute = static fn (): mixed => $scope($handlerExecution);
        }
        $result = $this->guardDispatchDepth(
            function () use ($command, $context, $execute): mixed {
                if ($context->idempotencyKey !== null && $this->idempotencyStore instanceof IdempotencyStoreInterface) {
                    return $this->idempotencyStore->remember(
                        hash('sha256', $command::class . '|' . $context->idempotencyKey),
                        $execute,
                        $this->idempotencyTtlSeconds,
                    );
                }

                return $execute();
            },
        );
        // Fan out only for THIS invocation: $pendingEvents stays empty on
        // an idempotent replay, so events are never re-fired. The first
        // caller observes EventDispatchException; all listeners already
        // ran by then (aggregating dispatcher).
        //
        // With a TransactionManager wired (v2.22.0), fan-out is queued via
        // afterCommit(): events fire only after the OUTERMOST transaction
        // commits — a rolled-back command emits nothing, and listeners
        // observe committed data. Without one (or outside a managed
        // scope), afterCommit executes immediately, preserving the exact
        // pre-2.22 timing.
        foreach ($pendingEvents as $event) {
            $fanOut = function () use ($event, $context): void {
                $this->eventBus?->dispatchWithContext($event, $context->toEventContext());
            };
            if ($transactions instanceof TransactionManagerInterface) {
                $transactions->afterCommit($fanOut);

                continue;
            }
            $fanOut();
        }

        return $result;
    }
}
