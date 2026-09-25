<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Monotonic nanosecond clock backed by hrtime() — the production default
 * for the v2.26.0 async scheduler (timers, deadlines, timeout budgets).
 *
 * Lives in the Application layer as the ordinary default implementation of
 * the Domain port; tests inject a manually-advanced fake clock instead of
 * stubbing hrtime(), which keeps every timer behaviour deterministic.
 */
final class HrMonotonicClock implements MonotonicClockInterface
{
    #[\Override]
    public function nowNano(): int
    {
        return hrtime(true);
    }
}
