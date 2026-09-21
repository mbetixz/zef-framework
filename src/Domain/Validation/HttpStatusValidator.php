<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

final class HttpStatusValidator
{
    public function assert(int $code): void
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException("Invalid HTTP status code: {$code}.");
        }
    }
}
