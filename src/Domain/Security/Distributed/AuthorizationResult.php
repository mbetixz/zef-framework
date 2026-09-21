<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class AuthorizationResult
{
    public function __construct(
        public SecurityVerdict $verdict,
        public string $policyCode,
    ) {
        if ($policyCode === '' || strlen($policyCode) > 128) {
            throw new \InvalidArgumentException('policyCode exceeds its bound.');
        }
    }
}
