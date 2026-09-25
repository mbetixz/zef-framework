<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Domain layer: ports, contracts, value objects)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * A rate limiter that understands WEIGHTED hits.
 *
 * Extends the simple {@see RateLimiterInterface} quota check with a cost:
 * one `consume()` call can withdraw more than one unit (a report generation
 * costing 5, a bulk export costing 20), so a single window can mix cheap and
 * expensive operations under one quota.
 *
 * Implementations MUST be atomic per call (the cost is either fully
 * withdrawn or the call is denied — never partially charged) and MUST
 * validate `cost >= 1` and `cost <= limit` (a cost larger than the whole
 * window quota can never be satisfied and is a configuration error, not a
 * permanent denial).
 */
interface CostAwareRateLimiterInterface extends RateLimiterInterface
{
    public function consume(string $key, int $limit, int $windowSeconds, int $cost = 1): RateLimitDecision;
}
