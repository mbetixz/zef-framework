<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Validation\Identifier;

final readonly class JobResult
{
    public function __construct(
        public string $jobId,
        public bool $completed,
        public mixed $result = null,
        public int $attempt = 1,
        /** True only when the job was actually persisted to the DLQ. */
        public bool $deadLettered = false,
        /** True when the job was re-enqueued and will be retried. */
        public bool $willRetry = false,
    ) {
        Identifier::assertOpaqueId($jobId, 'result job ID');
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Job result attempt must be positive.');
        }
    }
}
