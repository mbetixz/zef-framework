<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

enum SecurityFailure: string
{
    case NONE = 'none';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case AUTHENTICATION_UNAVAILABLE = 'authentication_unavailable';
    case CREDENTIAL_EXPIRED = 'credential_expired';
    case AUTHORIZATION_DENIED = 'authorization_denied';
    case REPLAY_REJECTED = 'replay_rejected';
    case REPLAY_UNAVAILABLE = 'replay_unavailable';
    case MALFORMED_METADATA = 'malformed_metadata';
}
