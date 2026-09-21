<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Job;

/**
 * Recurrence policy for scheduled jobs. All arithmetic happens on unix
 * nanoseconds so schedules compose with JobEnvelope::availableAtUnixNano
 * without unit confusion.
 */
interface ScheduleInterface
{
    /**
     * First run strictly after $nowUnixNano (monotonic: result > now).
     */
    public function nextRunAfter(int $nowUnixNano): int;

    /** Human-readable description for logging and route/dashboard output. */
    public function describe(): string;
}
