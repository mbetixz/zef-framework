<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

interface MetricExporterInterface
{
    /** @param array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}> $metrics */
    public function exportMetrics(array $metrics): void;

    public function shutdown(): void;
}
