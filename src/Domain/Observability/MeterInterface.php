<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

interface MeterInterface
{
    /** @param array<string,mixed> $attributes */
    public function increment(string $name, float|int $value = 1, array $attributes = []): void;

    /** @param array<string,mixed> $attributes */
    public function observe(string $name, float $value, array $attributes = []): void;

    /** @return array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}> */
    public function snapshot(): array;
}
