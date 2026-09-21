<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Resource;

final readonly class ResourceBudget
{
    public function __construct(
        public int $maxInFlight,
        public int $maxQueue,
    ) {
        if ($maxInFlight < 1 || $maxInFlight > 1024) {
            throw new \InvalidArgumentException('maxInFlight must be between 1 and 1024.');
        }
        if ($maxQueue < 0 || $maxQueue > 100_000) {
            throw new \InvalidArgumentException('maxQueue must be between 0 and 100000.');
        }
    }
}
