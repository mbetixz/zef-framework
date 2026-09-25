<?php

declare(strict_types=1);

/*
 * ZEF Framework — test fixture: deterministic nanosecond clock.
 *
 * Tests advance time by assigning `->ns` directly; production limiters only
 * ever see the CacheClockInterface port.
 */

namespace Zef\Test\Unit;

use Zef\Framework\Cache\CacheClockInterface;

final class FakeRateClock implements CacheClockInterface
{
    public function __construct(public int $ns = 0) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        return $this->ns;
    }
}
