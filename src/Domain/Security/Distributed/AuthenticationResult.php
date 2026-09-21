<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class AuthenticationResult
{
    public function __construct(
        public AuthenticationStatus $status,
        public ?SecurityContext $context = null,
    ) {
        if ($status === AuthenticationStatus::AUTHENTICATED && !$context instanceof SecurityContext) {
            throw new \InvalidArgumentException('Authenticated result requires a security context.');
        }
        if ($status !== AuthenticationStatus::AUTHENTICATED && $context instanceof SecurityContext) {
            throw new \InvalidArgumentException('Non-authenticated result cannot carry a security context.');
        }
    }
}
