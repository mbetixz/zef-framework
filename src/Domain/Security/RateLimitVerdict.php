<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Domain layer: value objects)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * The aggregate result of evaluating a set of {@see RateLimitRule} against
 * one identity (see {@see TieredRateLimiter::evaluateAll()}).
 *
 * Aggregation semantics ("most restrictive wins"):
 * - `allowed` — true only if EVERY matching rule admitted the request;
 * - `limit` — the tightest quota among the evaluated rules (what a client
 *   should see in `RateLimit-Limit`);
 * - `remaining` — the smallest remaining budget across rules;
 * - `retryAfter` — when denied, the LONGEST wait any denied rule demands
 *   (seconds, ceil); 0 when allowed;
 * - `resetAfter` — seconds until the most restrictive window fully resets.
 *
 * The per-rule detail stays available in `$outcomes` for logs and RFC-draft
 * style headers, so a denial is always explainable ("auth tier exhausted").
 */
final readonly class RateLimitVerdict
{
    /**
     * @param list<RateLimitRuleOutcome> $outcomes
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
        public int $resetAfter,
        public array $outcomes,
    ) {
        if ($outcomes === []) {
            throw new \InvalidArgumentException('Rate limit verdict requires at least one rule outcome.');
        }
    }

    /**
     * Aggregates per-rule outcomes into the "most restrictive wins" verdict.
     *
     * @param list<RateLimitRuleOutcome> $outcomes
     *
     * @throws \InvalidArgumentException when the list is empty
     */
    public static function fromOutcomes(array $outcomes): self
    {
        if ($outcomes === []) {
            throw new \InvalidArgumentException('Cannot aggregate an empty rate limit outcome list.');
        }
        $allowed = true;
        $limit = PHP_INT_MAX;
        $remaining = PHP_INT_MAX;
        $retryAfter = 0;
        $resetAfter = 0;
        foreach ($outcomes as $outcome) {
            $allowed = $allowed && $outcome->allowed;
            $limit = min($limit, $outcome->limit);
            $remaining = min($remaining, $outcome->remaining);
            $retryAfter = $outcome->allowed ? $retryAfter : max($retryAfter, $outcome->retryAfter);
            $resetAfter = max($resetAfter, $outcome->resetAfter);
        }

        return new self($allowed, $limit, $remaining, $retryAfter, $resetAfter, $outcomes);
    }
}
