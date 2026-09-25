<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

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

    /**
     * Issue #55 step 3 (Domain module): the three retry knobs flow through
     * the EnvInterface port — the static facade is gone from this file. The
     * parameter is optional and defaults to the concrete Env, so existing
     * callers keep working unchanged.
     */
    public static function fromEnvironment(?EnvInterface $env = null): self
    {
        $env ??= new Env();
        $initial = $env->readInt('ZEF_OTEL_RETRY_DELAY_MS', 100, 0, 10000);

        return new self(
            $env->readInt('ZEF_OTEL_RETRY_ATTEMPTS', 2, 0, 10),
            $initial,
            max($initial, $env->readInt('ZEF_OTEL_RETRY_DELAY_CAP_MS', 1000, 0, 60000)),
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
