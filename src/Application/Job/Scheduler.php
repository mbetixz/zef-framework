<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Validation\Identifier;

/**
 * Cron-like scheduler feeding due scheduled jobs into a JobQueueInterface.
 *
 * Registrations pair a job type + payload with a ScheduleInterface. Each
 * `tick($nowUnixNano)` enqueues one envelope per registration whose next due
 * time has arrived, then advances that registration's cursor. Fire times come
 * from the schedule itself (epoch-aligned for fixed intervals, wall-clock
 * aligned for cron), so restarts never drift or double-fire within a minute.
 *
 * The worker that drains the queue provides retries; the scheduler only
 * guarantees "due jobs become visible".
 */
final class Scheduler
{
    /**
     * @var array<string,array{schedule:ScheduleInterface,payload:mixed,correlationId:?string,nextRunUnixNano:null|int}>
     */
    private array $registrations = [];

    public function __construct(
        private readonly JobQueueInterface $queue,
        private readonly int $maxRegistrations = 256,
        private readonly int $maxCatchUpPerTick = 8,
    ) {
        if ($this->maxRegistrations < 1 || $this->maxCatchUpPerTick < 1) {
            throw new \InvalidArgumentException('Scheduler budgets must be positive.');
        }
    }

    /**
     * Register a recurring job. Re-registering the same jobType replaces the
     * previous schedule (keeping its cursor).
     */
    public function register(
        string $jobType,
        mixed $payload,
        ScheduleInterface $schedule,
        ?string $correlationId = null,
    ): void {
        Identifier::assertMessageType($jobType, 'scheduled job type');
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'scheduler correlation ID');
        }
        if (!isset($this->registrations[$jobType]) && count($this->registrations) >= $this->maxRegistrations) {
            throw new \OverflowException("Scheduler registration budget exceeded ({$this->maxRegistrations}).");
        }
        $this->registrations[$jobType] = [
            'schedule' => $schedule,
            'payload' => $payload,
            'correlationId' => $correlationId,
            'nextRunUnixNano' => null,
        ];
    }

    public function unregister(string $jobType): bool
    {
        Identifier::assertMessageType($jobType, 'scheduled job type');
        if (!isset($this->registrations[$jobType])) {
            return false;
        }
        unset($this->registrations[$jobType]);

        return true;
    }

    /** @return list<string> registered job types in registration order */
    public function jobTypes(): array
    {
        return array_keys($this->registrations);
    }

    public function nextRunOf(string $jobType): ?int
    {
        return $this->registrations[$jobType]['nextRunUnixNano'] ?? null;
    }

    public function scheduleOf(string $jobType): ?ScheduleInterface
    {
        return $this->registrations[$jobType]['schedule'] ?? null;
    }

    /**
     * Advance the clock: enqueue due jobs. Returns the number of envelopes
     * enqueued. Per registration at most $maxCatchUpPerTick envelopes are
     * produced per tick (protects against huge catch-up bursts after
     * downtime); the cursor still advances past the due horizon.
     */
    public function tick(int $nowUnixNano): int
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Scheduler tick time must be non-negative.');
        }
        $enqueued = 0;
        foreach ($this->registrations as $jobType => $state) {
            $next = $state['nextRunUnixNano']
                ?? $state['schedule']->nextRunAfter($nowUnixNano - 1);
            $produced = 0;
            while ($next <= $nowUnixNano && $produced < $this->maxCatchUpPerTick) {
                $this->queue->enqueue(new JobEnvelope(
                    jobId: $this->newJobId(),
                    jobType: $jobType,
                    payload: $state['payload'],
                    availableAtUnixNano: $next,
                    correlationId: $state['correlationId'],
                ));
                ++$produced;
                ++$enqueued;
                $next = $state['schedule']->nextRunAfter($next);
            }
            $this->registrations[$jobType]['nextRunUnixNano'] = $next;
        }

        return $enqueued;
    }

    private function newJobId(): string
    {
        return 'sched-' . bin2hex(random_bytes(8));
    }
}
