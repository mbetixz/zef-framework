<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Validation\Identifier;

final readonly class JobContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $jobId,
        public int $attempt,
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $attributes = [],
        private ?int $deadlineUnixNano = null,
        private bool $cancelled = false,
    ) {
        Identifier::assertOpaqueId($jobId, 'job ID');
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Job attempt must be positive.');
        }
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'job correlation ID');
        }
        if ($traceParent !== null) {
            Identifier::assertTraceParent($traceParent, 'W3C traceparent');
        }
        foreach ($attributes as $key => $_) {
            if ($key === '' || strlen($key) > 128) {
                throw new \InvalidArgumentException('Invalid job context attribute key.');
            }
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function deadlineUnixNano(): ?int
    {
        return $this->deadlineUnixNano;
    }

    public function isTimedOut(?int $nowUnixNano = null): bool
    {
        return $this->deadlineUnixNano !== null
            && ($nowUnixNano ?? (int) (microtime(true) * 1_000_000_000)) >= $this->deadlineUnixNano;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new JobCancelledException('Job execution was cancelled.');
        }
        if ($this->isTimedOut()) {
            throw new JobTimeoutException('Job execution deadline exceeded.');
        }
    }

    public function withDeadlineMs(int $timeoutMs): self
    {
        if ($timeoutMs < 1) {
            throw new \InvalidArgumentException('Job timeout must be positive.');
        }

        return new self(
            $this->jobId,
            $this->attempt,
            $this->correlationId,
            $this->traceParent,
            $this->attributes,
            (int) (microtime(true) * 1_000_000_000) + ($timeoutMs * 1_000_000),
            $this->cancelled,
        );
    }

    public function cancel(): self
    {
        return new self(
            $this->jobId,
            $this->attempt,
            $this->correlationId,
            $this->traceParent,
            $this->attributes,
            $this->deadlineUnixNano,
            true,
        );
    }
}
