<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Monotonic nanosecond clock port for the async scheduler.
 *
 * Timers, deadlines and timeout budgets are all computed against this port.
 * Monotonicity (never going backwards) is the contract that keeps
 * deadline arithmetic sane; wall-clock sources are forbidden here. The
 * production default is hrtime(); tests inject a manually-advanced fake to
 * keep timer behaviour fully deterministic.
 */
interface MonotonicClockInterface
{
    /**
     * Monotonic time in nanoseconds since an unspecified origin. Only
     * differences between two readings are meaningful.
     */
    public function nowNano(): int;
}
