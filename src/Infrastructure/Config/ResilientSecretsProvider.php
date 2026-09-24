<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.1 (resilient secrets decorator: bounded retries
 * with exponential backoff, a retry observability hook that never sees secret
 * material, and opt-in stale-value fallback for transient provider failures).
 */

namespace Zef\Framework\Config;

/**
 * Decorator over {@see SecretsProviderInterface} that absorbs transient
 * provider failures (network blips, throttling, brief permission races)
 * instead of failing the whole configuration boot on the first hiccup.
 *
 * Resolution order per key:
 * 1. up to `$maxAttempts` attempts against the inner provider, sleeping
 *    `$backoffSeconds * 2^(attempt-1)` between failures (capped at 30s);
 * 2. on final failure, the last known-good value for that key — ONLY when
 *    `$preferStaleOnFailure` is true and one was observed earlier;
 * 3. otherwise the original exception propagates: fail-fast is preserved
 *    whenever there is no trustworthy value to fall back to.
 *
 * Scope is deliberately narrow: this is retry + stale fallback, not a circuit
 * breaker. Semantics of the inner provider (key grammar, unknown-vs-empty
 * distinction) are delegated untouched — a null return (unknown key) is passed
 * through as-is and never cached, so keys can still be created later. The
 * `$onRetry` hook receives the key, the failing attempt number and the cause
 * — never secret material — keeping the port's "do not log secrets" contract
 * intact for structured logging.
 */
final class ResilientSecretsProvider implements SecretsProviderInterface
{
    /** Upper bound for a single backoff sleep, in seconds. */
    private const float MAX_BACKOFF_SECONDS = 30.0;

    /**
     * @var array<string,string> last known-good values, per key
     */
    private array $cache = [];

    /**
     * @param null|\Closure(float):void $sleeper injectable sleep strategy
     *                                           (tests pass a spy; production
     *                                           leaves null for usleep)
     * @param null|\Closure(string,int,\Throwable):void $onRetry invoked before
     *                                                           each sleep when a retry will follow (key, 1-based
     *                                                           attempt, cause)
     */
    public function __construct(
        private readonly SecretsProviderInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly float $backoffSeconds = 0.05,
        private readonly bool $preferStaleOnFailure = true,
        private readonly ?\Closure $sleeper = null,
        private readonly ?\Closure $onRetry = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Secrets retry policy needs at least one attempt.');
        }
        if ($backoffSeconds < 0.0) {
            throw new \InvalidArgumentException('Secrets backoff must be a non-negative number of seconds.');
        }
    }

    #[\Override]
    public function get(string $key): ?string
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $value = $this->inner->get($key);
            } catch (\Throwable $e) {
                if ($attempt >= $this->maxAttempts) {
                    return $this->onFinalFailure($key, $e);
                }
                if ($this->onRetry instanceof \Closure) {
                    ($this->onRetry)($key, $attempt, $e);
                }
                $this->sleep($attempt);

                continue;
            }
            if ($value !== null) {
                $this->cache[$key] = $value;
            }

            return $value;
        }
    }

    /**
     * Exhausted the retry budget: fall back to the last known-good value for
     * this key when allowed, otherwise propagate the original failure.
     */
    private function onFinalFailure(string $key, \Throwable $cause): string
    {
        if ($this->preferStaleOnFailure && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        throw $cause;
    }

    private function sleep(int $attempt): void
    {
        $seconds = min(
            self::MAX_BACKOFF_SECONDS,
            $this->backoffSeconds * (2 ** ($attempt - 1)),
        );
        if ($this->sleeper instanceof \Closure) {
            ($this->sleeper)($seconds);

            return;
        }
        if ($seconds > 0.0) {
            usleep((int) ceil($seconds * 1_000_000));
        }
    }
}
