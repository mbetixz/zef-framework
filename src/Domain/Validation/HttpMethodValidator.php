<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

final class HttpMethodValidator
{
    public static function assert(string $method): void
    {
        if ($method === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) {
            throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
        }
    }
}
