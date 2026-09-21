<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

final class SystemCacheClock implements CacheClockInterface
{
    #[\Override]
    public function nowUnixNano(): int
    {
        return hrtime(true);
    }
}
