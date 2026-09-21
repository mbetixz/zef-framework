<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

final class QueryBus implements QueryBusInterface
{
    use CqrsBusTrait;

    #[\Override]
    public function register(string $queryClass, callable|QueryHandlerInterface $handler): void
    {
        $this->assertMutable();
        $this->validateMessageClass($queryClass, 'query');
        if (isset($this->handlers[$queryClass])) {
            throw new CqrsHandlerConflictException("Query handler already registered for '{$queryClass}'.");
        }
        $this->handlers[$queryClass] = $handler instanceof QueryHandlerInterface
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
    public function ask(object $query, ?CqrsContext $context = null): mixed
    {
        $context ??= CqrsContext::create();

        return $this->guardDispatchDepth(function () use ($query, $context): mixed {
            $handler = $this->resolveHandler($query, 'query');
            $next = $this->buildChain($handler);

            return $next($query, $context);
        });
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
