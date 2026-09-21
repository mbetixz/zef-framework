<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Zef\Framework\Foundation\Env;

final readonly class RetryBackoffPolicy
{
    public function __construct(
        public int $maxRetries,
        public int $initialDelayMs,
        public int $maxDelayMs,
    ) {
        if ($maxRetries < 0 || $initialDelayMs < 0 || $maxDelayMs < $initialDelayMs) {
            throw new \InvalidArgumentException('Invalid telemetry retry policy.');
        }
    }

    public static function fromEnvironment(): self
    {
        $initial = Env::int('ZEF_OTEL_RETRY_DELAY_MS', 100, 0, 10000);

        return new self(
            Env::int('ZEF_OTEL_RETRY_ATTEMPTS', 2, 0, 10),
            $initial,
            max($initial, Env::int('ZEF_OTEL_RETRY_DELAY_CAP_MS', 1000, 0, 60000)),
        );
    }

    public function shouldRetry(int $retryIndex): bool
    {
        if ($retryIndex < 0) {
            throw new \InvalidArgumentException('Retry index must be non-negative.');
        }

        return $retryIndex < $this->maxRetries;
    }

    public function delayMs(int $retryIndex): int
    {
        if ($retryIndex < 0) {
            throw new \InvalidArgumentException('Retry index must be non-negative.');
        }

        return min($this->maxDelayMs, $this->initialDelayMs * (2 ** $retryIndex));
    }
}
