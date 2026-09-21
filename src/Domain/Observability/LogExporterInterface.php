<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

interface LogExporterInterface
{
    /** @param list<LogRecord> $records */
    public function exportLogs(array $records): void;

    public function shutdown(): void;
}
