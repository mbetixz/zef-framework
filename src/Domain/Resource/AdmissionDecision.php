<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Resource;

final readonly class AdmissionDecision
{
    public function __construct(
        public bool $admitted,
        public string $reason,
        public int $inFlight,
        public int $queued,
    ) {
        if ($reason === '' || strlen($reason) > 64) {
            throw new \InvalidArgumentException('Admission reason is invalid.');
        }
    }
}
