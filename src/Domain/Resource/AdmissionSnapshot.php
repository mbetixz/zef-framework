<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Resource;

final readonly class AdmissionSnapshot
{
    public function __construct(
        public int $admitted,
        public int $rejected,
        public int $queued,
        public int $completed,
    ) {}
}
