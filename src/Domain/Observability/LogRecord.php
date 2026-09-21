<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final readonly class LogRecord
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $severity,
        public string $body,
        public int $timeUnixNano,
        public array $attributes = [],
    ) {}
}
