<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Job;

/**
 * Fixed-rate schedule: run every N seconds from the epoch, so fire times are
 * aligned (N=3600 fires on wall-clock hour boundaries) and stable across
 * process restarts.
 */
final readonly class FixedIntervalSchedule implements ScheduleInterface
{
    public function __construct(
        public int $intervalSeconds,
    ) {
        if ($this->intervalSeconds < 1 || $this->intervalSeconds > 31536000) {
            throw new \InvalidArgumentException('Interval must be 1..31536000 seconds.');
        }
    }

    #[\Override]
    public function nextRunAfter(int $nowUnixNano): int
    {
        $stepNs = $this->intervalSeconds * 1_000_000_000;

        return (intdiv($nowUnixNano, $stepNs) + 1) * $stepNs;
    }

    #[\Override]
    public function describe(): string
    {
        return "every {$this->intervalSeconds}s";
    }
}
