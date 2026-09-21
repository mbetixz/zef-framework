<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

enum ReplayDecision: string
{
    case NOT_REQUIRED = 'not_required';
    case ACCEPT = 'accept';
    case DUPLICATE = 'duplicate';
    case STALE = 'stale';
    case REJECTED = 'rejected';
    case UNAVAILABLE = 'unavailable';
}
