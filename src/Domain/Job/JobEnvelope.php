<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Validation\Identifier;

final readonly class JobEnvelope
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $jobId,
        public string $jobType,
        public mixed $payload,
        public int $availableAtUnixNano,
        public int $priority = 0,
        public int $attempt = 1,
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $headers = [],
    ) {
        Identifier::assertOpaqueId($jobId, 'job ID');
        Identifier::assertMessageType($jobType, 'job type');
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Job attempt must be positive.');
        }
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'job correlation ID');
        }
        if ($traceParent !== null) {
            Identifier::assertTraceParent($traceParent, 'W3C traceparent');
        }
        if (count($headers) > 32) {
            throw new \InvalidArgumentException('Job header count exceeds the limit.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid job header name.');
            }
            if (!is_string($value) || strlen($value) > 2048) {
                throw new \InvalidArgumentException('Job header value must be a string within the limit.');
            }
        }
    }

    public function nextAttempt(int $delayMs): self
    {
        if ($delayMs < 0) {
            throw new \InvalidArgumentException('Job retry delay cannot be negative.');
        }

        return new self(
            $this->jobId,
            $this->jobType,
            $this->payload,
            (int) (microtime(true) * 1_000_000_000) + ($delayMs * 1_000_000),
            $this->priority,
            $this->attempt + 1,
            $this->correlationId,
            $this->traceParent,
            $this->headers,
        );
    }
}
