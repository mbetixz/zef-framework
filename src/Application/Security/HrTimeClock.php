<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\SystemCacheClock;

/**
 * Monotonic nanosecond clock (hrtime) — the production default for the
 * v2.25.0 rate-limiting algorithms.
 *
 * Lives in the Application layer because the Infrastructure
 * {@see SystemCacheClock} cannot be imported from here
 * (architecture rule: Application depends only on Domain); the algorithms
 * only need the port, and hrtime is the natural monotonic source for
 * continuous-refill arithmetic. Tests inject a fake clock instead.
 */
final class HrTimeClock implements CacheClockInterface
{
    #[\Override]
    public function nowUnixNano(): int
    {
        return hrtime(true);
    }
}
