<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Domain layer: value objects)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * The outcome of evaluating ONE {@see RateLimitRule} against ONE identity.
 *
 * A tiered evaluation produces one outcome per matching rule; the aggregate
 * verdict lives in {@see RateLimitVerdict}. Keeping the per-rule detail lets
 * callers (middleware, logs, diagnostics) explain WHY a request was denied
 * — which tier ate the quota — instead of exposing a bare boolean.
 */
final readonly class RateLimitRuleOutcome
{
    public function __construct(
        public string $ruleName,
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
        public int $resetAfter,
    ) {
        if ($ruleName === '') {
            throw new \InvalidArgumentException('Rate limit rule outcome name must not be empty.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('Rate limit rule outcome limit must be >= 1.');
        }
        if ($remaining < 0) {
            throw new \InvalidArgumentException('Rate limit rule outcome remaining must be >= 0.');
        }
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('Rate limit rule outcome retryAfter must be >= 0.');
        }
        if ($resetAfter < 0) {
            throw new \InvalidArgumentException('Rate limit rule outcome resetAfter must be >= 0.');
        }
    }
}
