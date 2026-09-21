<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

final readonly class ConfigurationSnapshot
{
    /** @param array<string,mixed> $values */
    public function __construct(public array $values, public int $version = 1)
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Configuration version must be positive.');
        }
    }
}
