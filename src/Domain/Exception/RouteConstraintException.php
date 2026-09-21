<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

final class RouteConstraintException extends \RuntimeException
{
    public function __construct(
        public readonly string $param,
        public readonly string $type,
        public readonly string $value,
    ) {
        parent::__construct("Route param '{$param}' failed constraint '{$type}'.");
    }
}
