<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

interface QueryBusInterface
{
    public function register(string $queryClass, callable|QueryHandlerInterface $handler): void;

    public function use(CqrsMiddlewareInterface $middleware): void;

    public function ask(object $query, ?CqrsContext $context = null): mixed;

    public function freeze(): void;

    public function isFrozen(): bool;
}
