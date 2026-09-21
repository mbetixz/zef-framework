<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelayMs = 100,
        public int $maxDelayMs = 30_000,
        public float $multiplier = 2.0,
        public int $jitterMs = 0,
    ) {
        if (
            $maxAttempts < 1
            || $initialDelayMs < 0
            || $maxDelayMs < $initialDelayMs
            || $multiplier < 1.0
            || $jitterMs < 0
        ) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
    }

    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    public function delayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Attempt must be positive.');
        }
        $raw = (int) round($this->initialDelayMs * ($this->multiplier ** max(0, $attempt - 1)));
        $delay = min($this->maxDelayMs, $raw);
        if ($this->jitterMs > 0) {
            $delay += random_int(0, $this->jitterMs);
        }

        return min($this->maxDelayMs, $delay);
    }
}
