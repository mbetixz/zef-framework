<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * APCu-backed rate limiter.
 *
 * Bug fix #5: uses apcu_add for counter init to avoid clobbering concurrent
 * increments (documented, not self-testable without APCu).
 */
final class ApcuRateLimiter implements RateLimiterInterface
{
    private const string PREFIX = 'zef:ratelimit:';

    public function __construct(private readonly int $maxKeys = 10000)
    {
        if ($this->maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
        }
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('APCu extension is required for ApcuRateLimiter.');
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
        $hash = hash('sha256', $key);
        $counterKey = self::PREFIX . 'c:' . $hash;
        $windowKey = self::PREFIX . 'w:' . $hash;
        $reset = $now + $windowSeconds;
        if (apcu_add($windowKey, $reset, $windowSeconds + 60)) {
            // Bug fix #5: use apcu_add (not apcu_store) to avoid clobbering
            // a concurrently incremented counter.
            apcu_add($counterKey, 0, $windowSeconds + 60);
        } else {
            $stored = apcu_fetch($windowKey, $hit);
            if (!($hit === true && is_int($stored) && $stored > $now)) {
                apcu_store($windowKey, $reset, $windowSeconds + 60);
                apcu_store($counterKey, 0, $windowSeconds + 60);
            } else {
                $reset = $stored;
            }
        }
        $count = apcu_inc($counterKey, 1, $success, $windowSeconds + 60);
        if ($success !== true) {
            apcu_store($counterKey, 1, $windowSeconds + 60);
            $count = 1;
        }
        $remaining = max(0, $limit - $count);

        return new RateLimitDecision($count <= $limit, $limit, $remaining, max(1, $reset - $now));
    }
}
