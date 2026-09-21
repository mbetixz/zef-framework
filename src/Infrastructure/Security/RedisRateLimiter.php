<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

final class RedisRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly SharedRateLimitStoreInterface $store,
        private readonly int $maxKeys = 10000,
    ) {
        if ($this->maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
        }
    }

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Rate limit key must not be empty.');
        }
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('limit and windowSeconds must be >= 1.');
        }
        $now = time();
        ['count' => $count, 'reset' => $reset] = $this->store->increment($key, $windowSeconds, $now);
        $remaining = max(0, $limit - $count);

        return new RateLimitDecision($count <= $limit, $limit, $remaining, max(1, $reset - $now));
    }
}
