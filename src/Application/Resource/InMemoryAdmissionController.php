<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Resource;

final class InMemoryAdmissionController implements AdmissionControllerInterface
{
    private int $inFlight = 0;
    private int $queued = 0;
    private int $admitted = 0;
    private int $rejected = 0;
    private int $completed = 0;

    public function __construct(private readonly ResourceBudget $budget) {}

    #[\Override]
    public function admit(): AdmissionDecision
    {
        if ($this->inFlight >= $this->budget->maxInFlight) {
            ++$this->rejected;

            return new AdmissionDecision(false, 'in_flight_limit', $this->inFlight, $this->queued);
        }
        ++$this->inFlight;
        ++$this->admitted;

        return new AdmissionDecision(true, 'admitted', $this->inFlight, $this->queued);
    }

    #[\Override]
    public function complete(): void
    {
        if ($this->inFlight < 1) {
            throw new \LogicException('Cannot complete without an admitted operation.');
        }
        --$this->inFlight;
        ++$this->completed;
    }

    #[\Override]
    public function queue(): AdmissionDecision
    {
        if ($this->queued >= $this->budget->maxQueue) {
            ++$this->rejected;

            return new AdmissionDecision(false, 'queue_limit', $this->inFlight, $this->queued);
        }
        ++$this->queued;

        return new AdmissionDecision(true, 'queued', $this->inFlight, $this->queued);
    }

    #[\Override]
    public function dequeue(): void
    {
        if ($this->queued < 1) {
            throw new \LogicException('Cannot dequeue an empty queue.');
        }
        --$this->queued;
    }

    #[\Override]
    public function snapshot(): AdmissionSnapshot
    {
        return new AdmissionSnapshot($this->admitted, $this->rejected, $this->queued, $this->completed);
    }
}
