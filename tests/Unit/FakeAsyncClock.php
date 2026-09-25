<?php

declare(strict_types=1);

/*
 * ZEF Framework — test fixture: deterministic monotonic clock for the
 * v2.26.0 async runtime. Tests advance time explicitly; the scheduler only
 * ever sees the MonotonicClockInterface port.
 */

namespace Zef\Test\Unit;

use Zef\Framework\Runtime\Async\MonotonicClockInterface;

final class FakeAsyncClock implements MonotonicClockInterface
{
    public function __construct(public int $nano = 0) {}

    #[\Override]
    public function nowNano(): int
    {
        return $this->nano;
    }

    /** Advances the fake clock by the given duration in seconds. */
    public function advanceSeconds(float $seconds): void
    {
        $this->nano += (int) round($seconds * 1_000_000_000);
    }

    /** Advances the fake clock by the given duration in milliseconds. */
    public function advanceMilliseconds(int $ms): void
    {
        $this->nano += $ms * 1_000_000;
    }
}
