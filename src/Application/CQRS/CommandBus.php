<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

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
        return $this->dispatchInScope($command, $context, static fn (\Closure $execute): mixed => $execute(), $this->transactions);
    }

    /**
     * Execute the handler through a scope owned by a decorator. The store's
     * producer covers the entire scope, including its commit; event fan-out
     * follows publication of the result.
     *
     * @internal used by TransactionalCommandBus
     *
     * @param \Closure(\Closure(): mixed): mixed $scope
     * @param null|\Closure(): void $afterPublication propagate scope hook failures after caching
     */
    public function dispatchInScope(
        object $command,
        ?CqrsContext $context,
        \Closure $scope,
        ?TransactionManagerInterface $transactions,
        ?\Closure $afterPublication = null,
    ): mixed {
        $context ??= CqrsContext::create();
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
        $result = $this->guardDispatchDepth(
            function () use ($command, $context, $execute, $scope, $transactions): mixed {
                if ($context->idempotencyKey !== null && $this->idempotencyStore instanceof IdempotencyStoreInterface) {
                    return $this->idempotencyStore->remember(
                        hash('sha256', $command::class . '|' . $context->idempotencyKey),
                        static function () use ($execute, $scope, $transactions): mixed {
                            // remember() cannot hold publication until a caller-owned
                            // transaction commits. Reject a miss before any effects.
                            if ($transactions?->inTransaction() === true) {
                                throw new \LogicException('Idempotent command cache misses require an outermost TransactionalCommandBus dispatch.');
                            }

                            return $scope($execute);
                        },
                        $this->idempotencyTtlSeconds,
                    );
                }

                return $scope($execute);
            },
        );
        if ($afterPublication instanceof \Closure) {
            $afterPublication();
        }
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
}
