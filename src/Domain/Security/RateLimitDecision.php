<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * The outcome of one quota check/consume call against a single limiter key.
 *
 * `resetAfter` is the seconds-until-the-window-resets (fixed/sliding
 * families) or seconds-until-full (token bucket) value for response
 * headers; 0 means "not computed, mirror retryAfter".
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
        public int $resetAfter = 0,
    ) {}
}
