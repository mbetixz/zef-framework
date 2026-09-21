<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

final class MethodNotAllowedException extends \RuntimeException
{
    /** @param list<string> $allowedMethods */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $allowedMethods,
    ) {
        parent::__construct("Method '{$method}' is not allowed for {$path}.");
    }
}
