<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.25.0 Rate Limiting: algorithms, tiers, verdicts.
 *
 * Deterministic tests over a fake nanosecond clock: boundary-exact
 * assertions for the sliding-window interpolation and the token-bucket
 * refill, so a mutant in the arithmetic (ceil/floor flips, ratio inversion,
 * off-by-one in window indices) cannot survive.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\HrTimeClock;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimitAlgorithm;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\RateLimitRuleOutcome;
use Zef\Framework\Security\RateLimitVerdict;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;

/**
 * @internal
 */
final class RateLimitV25Test extends TestCase
{
    private const int SECOND = 1_000_000_000;

    // ------------------------------------------------------------------
    // RateLimitAlgorithm
    // ------------------------------------------------------------------

    public function testAlgorithmFromStringNormalisesWhitespaceAndCase(): void
    {
        self::assertSame(RateLimitAlgorithm::SlidingWindow, RateLimitAlgorithm::fromString(' SLIDING '));
        self::assertSame(RateLimitAlgorithm::TokenBucket, RateLimitAlgorithm::fromString('token'));
        self::assertSame(RateLimitAlgorithm::FixedWindow, RateLimitAlgorithm::fromString('FIXED'));
    }

    public function testAlgorithmFromStringRejectsUnknownNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rate limit algorithm');
        RateLimitAlgorithm::fromString('leaky-bucket');
    }

    // ------------------------------------------------------------------
    // RateLimitRule
    // ------------------------------------------------------------------

    public function testRuleDefaults(): void
    {
        $rule = new RateLimitRule('api', 10, 60);
        self::assertSame(1, $rule->cost);
        self::assertSame('/', $rule->pathPrefix);
        self::assertNull($rule->methods);
    }

    public function testRuleFromArrayFull(): void
    {
        $rule = RateLimitRule::fromArray([
            'name' => 'auth',
            'limit' => 5,
            'windowSeconds' => 30,
            'cost' => 2,
            'pathPrefix' => '/login',
            'methods' => ['POST', 'DELETE'],
        ]);
        self::assertSame('auth', $rule->name);
        self::assertSame(5, $rule->limit);
        self::assertSame(30, $rule->windowSeconds);
        self::assertSame(2, $rule->cost);
        self::assertSame('/login', $rule->pathPrefix);
        self::assertSame(['POST', 'DELETE'], $rule->methods);
    }

    public function testRuleFromArrayRejectsMissingKeys(): void
    {
        foreach (['name', 'limit', 'windowSeconds'] as $missing) {
            $data = ['name' => 'a', 'limit' => 1, 'windowSeconds' => 1];
            unset($data[$missing]);

            try {
                RateLimitRule::fromArray($data);
                self::fail(sprintf('Missing "%s" must be rejected.', $missing));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(sprintf('"%s"', $missing), $e->getMessage());
            }
        }
    }

    public function testRuleFromArrayRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rate limit rule key "burst"');
        RateLimitRule::fromArray(['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'burst' => 9]);
    }

    public function testRuleFromArrayRejectsWrongScalarTypes(): void
    {
        $base = ['name' => 'a', 'limit' => 1, 'windowSeconds' => 1];
        $thrown = 0;
        foreach ([['limit' => '5'], ['windowSeconds' => 1.5], ['cost' => '2'], ['pathPrefix' => 7], ['name' => 42]] as $override) {
            try {
                RateLimitRule::fromArray(array_merge($base, $override));
            } catch (\InvalidArgumentException) {
                ++$thrown;
            }
        }
        self::assertSame(5, $thrown, 'every scalar type violation must be rejected');
    }

    public function testRuleFromArrayRejectsAssociativeMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('methods');
        RateLimitRule::fromArray(['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => ['POST' => 'x']]);
    }

    public function testRuleConstructorValidations(): void
    {
        $cases = [
            [['name' => '', 'limit' => 1, 'windowSeconds' => 1], 'name must not be empty'],
            [['name' => 'a>b', 'limit' => 1, 'windowSeconds' => 1], 'must not contain ">"'],
            [['name' => str_repeat('x', 65), 'limit' => 1, 'windowSeconds' => 1], 'at most 64'],
            [['name' => 'a', 'limit' => 0, 'windowSeconds' => 1], 'limit must be >= 1'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 0], 'windowSeconds must be >= 1'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'cost' => 0], 'cost must be >= 1'],
            [['name' => 'a', 'limit' => 2, 'windowSeconds' => 1, 'cost' => 3], 'must not exceed the limit'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'pathPrefix' => ''], 'must start with "/"'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'pathPrefix' => 'api'], 'must start with "/"'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => []], 'non-empty list'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => ['get']], 'uppercase HTTP method'],
            [['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => ['NOT-A-METHOD']], 'uppercase HTTP method'],
        ];
        foreach ($cases as [$args, $message]) {
            try {
                new RateLimitRule(...$args);
                self::fail(sprintf('Expected rejection for: %s', $message));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testRuleMatchesPathSemantics(): void
    {
        $rule = new RateLimitRule('api', 10, 60, pathPrefix: '/api');
        self::assertTrue($rule->matchesPath('/api'));
        self::assertTrue($rule->matchesPath('/api/users'));
        self::assertTrue($rule->matchesPath('/api/users/42'));
        self::assertFalse($rule->matchesPath('/apiv2'));
        self::assertFalse($rule->matchesPath('/dashboard'));

        $root = new RateLimitRule('all', 10, 60);
        self::assertTrue($root->matchesPath('/anything/here'));
        self::assertTrue($root->matchesPath('/'));

        $trailing = new RateLimitRule('t', 10, 60, pathPrefix: '/api/');
        self::assertSame('/api', $trailing->pathPrefix, 'trailing slashes are normalised at construction');
        self::assertTrue($trailing->matchesPath('/api/users'));
        self::assertTrue($trailing->matchesPath('/api'), 'normalised prefix matches the bare prefix too');
        self::assertFalse($trailing->matchesPath('/apiv2'));
    }

    public function testRuleMatchesMethodSemantics(): void
    {
        $post = new RateLimitRule('write', 10, 60, methods: ['POST']);
        self::assertTrue($post->matchesMethod('POST'));
        self::assertTrue($post->matchesMethod('post'));
        self::assertFalse($post->matchesMethod('GET'));

        $all = new RateLimitRule('any', 10, 60);
        self::assertTrue($all->matchesMethod('PATCH'));
    }

    // ------------------------------------------------------------------
    // RateLimitRuleOutcome & RateLimitVerdict
    // ------------------------------------------------------------------

    public function testOutcomeValidations(): void
    {
        foreach ([
            ['', 1, 0, 0, 0, 'name must not be empty'],
            ['a', 0, 0, 0, 0, 'limit must be >= 1'],
            ['a', 1, -1, 0, 0, 'remaining must be >= 0'],
            ['a', 1, 0, -1, 0, 'retryAfter must be >= 0'],
            ['a', 1, 0, 0, -1, 'resetAfter must be >= 0'],
        ] as [$name, $limit, $remaining, $retryAfter, $resetAfter, $message]) {
            try {
                new RateLimitRuleOutcome($name, true, $limit, $remaining, $retryAfter, $resetAfter);
                self::fail(sprintf('Expected rejection: %s', $message));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testVerdictAggregatesMostRestrictiveWhenAllowed(): void
    {
        $verdict = RateLimitVerdict::fromOutcomes([
            new RateLimitRuleOutcome('wide', true, 100, 95, 0, 60),
            new RateLimitRuleOutcome('tight', true, 2, 1, 0, 5),
        ]);
        self::assertTrue($verdict->allowed);
        self::assertSame(2, $verdict->limit);
        self::assertSame(1, $verdict->remaining);
        self::assertSame(0, $verdict->retryAfter);
        self::assertSame(60, $verdict->resetAfter);
        self::assertCount(2, $verdict->outcomes);
    }

    public function testVerdictDeniedTakesLongestRetry(): void
    {
        $verdict = RateLimitVerdict::fromOutcomes([
            new RateLimitRuleOutcome('a', false, 5, 0, 7, 7),
            new RateLimitRuleOutcome('b', true, 5, 3, 0, 30),
            new RateLimitRuleOutcome('c', false, 5, 0, 12, 12),
        ]);
        self::assertFalse($verdict->allowed);
        self::assertSame(5, $verdict->limit);
        self::assertSame(0, $verdict->remaining);
        self::assertSame(12, $verdict->retryAfter);
        self::assertSame(30, $verdict->resetAfter);
    }

    public function testVerdictRejectsEmptyOutcomeList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        RateLimitVerdict::fromOutcomes([]);
    }

    public function testVerdictConstructorRejectsEmptyOutcomeList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one rule outcome');
        new RateLimitVerdict(true, 1, 0, 0, 0, []);
    }

    // ------------------------------------------------------------------
    // SlidingWindowRateLimiter
    // ------------------------------------------------------------------

    public function testSlidingFirstUseConsumesOne(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        $decision = $limiter->check('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(5, $decision->limit);
        self::assertSame(4, $decision->remaining);
        self::assertSame(10, $decision->retryAfter);
        self::assertSame(10, $decision->resetAfter);
    }

    public function testSlidingDeniesBeyondLimit(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->check('k', 5, 10)->allowed, "hit $i must pass");
        }
        $denied = $limiter->check('k', 5, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(0, $denied->remaining);
        self::assertSame(10, $denied->retryAfter);
    }

    public function testSlidingWeightedInterpolationAcrossBoundary(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->check('k', 5, 10);
        }
        // Half a window into the NEXT window, the previous full window
        // contributes ceil(5 * 0.5) = 3 units of virtual usage.
        $clock->ns = 15 * self::SECOND;
        $allowed = $limiter->check('k', 5, 10);
        self::assertTrue($allowed->allowed);
        self::assertSame(1, $allowed->remaining);

        // One nanosecond into the next window the full previous window still
        // counts: ceil(5 * ~1) = 5 -> deny.
        $clock2 = $this->clockAt(10 * self::SECOND + 1);
        $limiter2 = new SlidingWindowRateLimiter($clock2);
        for ($i = 0; $i < 5; ++$i) {
            $limiter2->check('k', 5, 10);
        }
        self::assertFalse($limiter2->check('k', 5, 10)->allowed);
    }

    public function testSlidingFullyResetsAfterTwoWindows(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->check('k', 5, 10);
        }
        $clock->ns = 20 * self::SECOND;
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->check('k', 5, 10)->allowed, "reset hit $i must pass");
        }
    }

    public function testSlidingRetryAfterRoundsToNextBoundary(): void
    {
        $clock = $this->clockAt(3 * self::SECOND);
        $limiter = new SlidingWindowRateLimiter($clock);
        self::assertTrue($limiter->check('k', 1, 10)->allowed);
        $denied = $limiter->check('k', 1, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(7, $denied->retryAfter);
    }

    public function testSlidingCostWithdrawsMultipleUnits(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        $first = $limiter->consume('k', 5, 10, 3);
        self::assertTrue($first->allowed);
        self::assertSame(2, $first->remaining);
        $second = $limiter->consume('k', 5, 10, 3);
        self::assertFalse($second->allowed, '2 used + 3 cost exceeds 5: deny');
        $cheap = $limiter->consume('k', 5, 10, 2);
        self::assertTrue($cheap->allowed);
        self::assertSame(0, $cheap->remaining);
    }

    public function testSlidingArgumentValidation(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        foreach ([
            ['', 5, 10, 1, 'key must not be empty'],
            ['k', 0, 10, 1, 'limit must be >= 1.'],
            ['k', 5, 0, 1, 'windowSeconds must be >= 1.'],
            ['k', 5, 10, 0, 'cost must be >= 1 and <= limit.'],
            ['k', 3, 10, 4, 'cost must be >= 1 and <= limit.'],
        ] as [$key, $limit, $window, $cost, $message]) {
            try {
                $limiter->consume($key, $limit, $window, $cost);
                self::fail(sprintf('Expected "%s" rejection.', $message));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSlidingCostAboveLimitIsRejected(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cost must be >= 1 and <= limit');
        $limiter->consume('k', 3, 10, 4);
    }

    public function testSlidingMaxKeysGuard(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0), 1);
        $limiter->check('a', 5, 10);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity exhausted');
        $limiter->check('b', 5, 10);
    }

    public function testSlidingGcFreesExpiredKeys(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock, 1);
        $limiter->check('a', 5, 10);
        // Two+ windows later, key "a" is fully stale and collectable.
        $clock->ns = 25 * self::SECOND;
        self::assertTrue($limiter->check('b', 5, 10)->allowed);
    }

    public function testSlidingMaxKeysConstructorGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SlidingWindowRateLimiter($this->clockAt(0), 0);
    }

    public function testSlidingWindowMismatchThrows(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        $limiter->check('k', 5, 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different window size');
        $limiter->check('k', 5, 20);
    }

    // ------------------------------------------------------------------
    // TokenBucketRateLimiter
    // ------------------------------------------------------------------

    public function testTokenStartsFullAndDepletes(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        $expected = [4, 3, 2, 1, 0];
        for ($i = 0; $i < 5; ++$i) {
            $decision = $limiter->consume('k', 5, 10);
            self::assertTrue($decision->allowed, "hit $i must pass (bucket starts full)");
            self::assertSame($expected[$i], $decision->remaining);
        }
        $denied = $limiter->consume('k', 5, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(0, $denied->remaining);
        self::assertSame(2, $denied->retryAfter);
    }

    public function testTokenRefillsContinuously(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        // Refill rate is 0.5 tokens/s: after 4s exactly 2 tokens accrued.
        $clock->ns = 4 * self::SECOND;
        $first = $limiter->consume('k', 5, 10);
        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        $second = $limiter->consume('k', 5, 10);
        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);
        $third = $limiter->consume('k', 5, 10);
        self::assertFalse($third->allowed);
    }

    public function testTokenDenialDoesNotStealFutureRefill(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        $clock->ns = 1 * self::SECOND;
        self::assertFalse($limiter->consume('k', 5, 10)->allowed);
        // The failed attempt must not anchor the refill clock at t=1s:
        // at t=2s the accrued tokens are 2 * 0.5 = 1 -> one more hit passes.
        $clock->ns = 2 * self::SECOND;
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(0, $decision->remaining);
    }

    public function testTokenRefillIsCappedAtCapacity(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        $limiter->consume('k', 5, 10);
        // 100s would refill 50 tokens; the cap must hold the level at 5.
        $clock->ns = 100 * self::SECOND;
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->consume('k', 5, 10)->allowed);
        }
        self::assertFalse($limiter->consume('k', 5, 10)->allowed);
    }

    public function testTokenWeightedConsume(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        $decision = $limiter->consume('k', 5, 10, 3);
        self::assertTrue($decision->allowed);
        self::assertSame(2, $decision->remaining);
        $denied = $limiter->consume('k', 5, 10, 3);
        self::assertFalse($denied->allowed);
        self::assertSame(2, $denied->retryAfter);
    }

    public function testTokenShapeMismatchThrows(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        $limiter->check('k', 5, 10);
        foreach ([[6, 10], [5, 20]] as [$limit, $window]) {
            try {
                $limiter->check('k', $limit, $window);
                self::fail(sprintf('Shape mismatch (limit=%d, window=%d) must throw.', $limit, $window));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('different limit/window shape', $e->getMessage());
            }
        }
    }

    public function testTokenMaxKeysGuardAndGcOfFullIdleBuckets(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock, 1);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('a', 5, 10);
        }

        try {
            $limiter->consume('b', 5, 10);
            self::fail('capacity must be exhausted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('capacity exhausted', $e->getMessage());
        }
        // After 20s the drained bucket refilled to full and has been idle a
        // whole window -> collectable; key "b" must now fit.
        $clock->ns = 20 * self::SECOND;
        self::assertTrue($limiter->consume('b', 5, 10)->allowed);
    }

    public function testTokenArgumentValidation(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        foreach ([
            ['', 5, 10, 1, 'key must not be empty'],
            ['k', 0, 10, 1, 'limit must be >= 1.'],
            ['k', 5, 0, 1, 'windowSeconds must be >= 1.'],
            ['k', 5, 10, 0, 'cost must be >= 1 and <= limit.'],
            ['k', 5, 10, 6, 'cost must be >= 1 and <= limit.'],
        ] as [$key, $limit, $window, $cost, $message]) {
            try {
                $limiter->consume($key, $limit, $window, $cost);
                self::fail(sprintf('Expected "%s" rejection.', $message));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testTokenMaxKeysConstructorGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBucketRateLimiter($this->clockAt(0), 0);
    }

    // ------------------------------------------------------------------
    // TieredRateLimiter
    // ------------------------------------------------------------------

    public function testTieredCostOneWorksWithPlainLimiter(): void
    {
        $tiered = new TieredRateLimiter(new InMemoryRateLimiter());
        $rule = new RateLimitRule('api', 2, 60);
        self::assertTrue($tiered->evaluate($rule, 'alice')->allowed);
        self::assertTrue($tiered->evaluate($rule, 'alice')->allowed);
        self::assertFalse($tiered->evaluate($rule, 'alice')->allowed);
    }

    public function testTieredWeightedRuleRejectsCostUnawareLimiter(): void
    {
        $tiered = new TieredRateLimiter(new InMemoryRateLimiter());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report" has cost 5');
        $tiered->evaluate(new RateLimitRule('report', 10, 60, cost: 5), 'alice');
    }

    public function testTieredIsolatesBucketsPerRule(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $api = new RateLimitRule('api', 2, 60);
        $auth = new RateLimitRule('auth', 2, 60);
        self::assertTrue($tiered->evaluate($api, 'alice')->allowed);
        self::assertTrue($tiered->evaluate($api, 'alice')->allowed);
        self::assertFalse($tiered->evaluate($api, 'alice')->allowed);
        self::assertTrue($tiered->evaluate($auth, 'alice')->allowed, 'auth bucket must be independent');
    }

    public function testTieredEvaluateAllAggregates(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $rules = [new RateLimitRule('wide', 100, 60), new RateLimitRule('tight', 1, 30)];
        $verdict = $tiered->evaluateAll($rules, 'alice');
        self::assertTrue($verdict->allowed);
        self::assertSame(1, $verdict->limit);
        self::assertSame(0, $verdict->remaining);
        self::assertSame(0, $verdict->retryAfter);
        self::assertSame(60, $verdict->resetAfter);

        $second = $tiered->evaluateAll($rules, 'alice');
        self::assertFalse($second->allowed);
        self::assertSame(30, $second->retryAfter);
        self::assertSame(60, $second->resetAfter);
    }

    public function testTieredEvaluateAllFallsBackToConstructorRules(): void
    {
        $rules = [new RateLimitRule('api', 5, 60)];
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)), $rules);
        $verdict = $tiered->evaluateAll(null, 'alice');
        self::assertTrue($verdict->allowed);
        self::assertSame(5, $verdict->limit);
    }

    public function testTieredEvaluateAllRejectsEmptyRules(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one rule');
        $tiered->evaluateAll([], 'alice');
    }

    public function testTieredIdentityValidation(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $rule = new RateLimitRule('api', 5, 60);

        try {
            $tiered->evaluate($rule, '');
            self::fail('empty identity must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not be empty', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 512');
        $tiered->evaluate($rule, str_repeat('x', 513));
    }

    public function testTieredRejectsNonRuleInstances(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RateLimitRule instances');
        new TieredRateLimiter(
            new InMemoryRateLimiter(),
            // @phpstan-ignore argument.type (deliberately non-rule entry for the runtime guard)
            [new \stdClass()],
        );
    }

    // ------------------------------------------------------------------
    // HrTimeClock
    // ------------------------------------------------------------------

    public function testHrTimeClockIsMonotonic(): void
    {
        $clock = new HrTimeClock();
        $first = $clock->nowUnixNano();
        self::assertGreaterThan(0, $first);
        $second = $clock->nowUnixNano();
        self::assertGreaterThanOrEqual($first, $second);
    }

    // ------------------------------------------------------------------
    // Back-compat: existing decision shape still constructs positionally
    // ------------------------------------------------------------------

    public function testRateLimitDecisionRemainsBackwardCompatible(): void
    {
        $decision = new RateLimitDecision(true, 5, 4, 10);
        self::assertSame(0, $decision->resetAfter, 'default resetAfter must mirror the legacy 4-field shape');
    }

    // ------------------------------------------------------------------
    // Mutation killers: boundaries, defaults, exact rounding
    // ------------------------------------------------------------------

    public function testSlidingCostDefaultsToOne(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(4, $decision->remaining, 'default cost must be exactly 1');
    }

    public function testSlidingOneSecondWindowIsUsable(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        $decision = $limiter->consume('k', 5, 1);
        self::assertTrue($decision->allowed);
        self::assertSame(4, $decision->remaining);
        self::assertSame(1, $decision->retryAfter);
    }

    public function testSlidingBoundaryOneNanoBeforeWindowEdge(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        self::assertTrue($limiter->consume('k', 1, 2)->allowed);
        // One nanosecond before the 2s window edge: still the SAME window,
        // so the denial must wait ~1ns (rounded up to 1s) — a limiter that
        // miscomputes the window length reports 2s.
        $clock->ns = 2 * self::SECOND - 1;
        $denied = $limiter->consume('k', 1, 2);
        self::assertFalse($denied->allowed);
        self::assertSame(1, $denied->retryAfter);
    }

    public function testSlidingWeightedUsageIsCeiledNotRounded(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        for ($i = 0; $i < 4; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        // 40% into the next window: virtual usage = ceil(4 * 0.6) = 3.
        $clock->ns = 14 * self::SECOND;
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(1, $decision->remaining, 'ceil(2.4)=3 used -> remaining 1 (round would give 2)');
    }

    public function testSlidingDenyRetryCeilsFractionalWait(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        self::assertTrue($limiter->consume('k', 1, 10)->allowed);
        $clock->ns = 3_600_000_000;
        $denied = $limiter->consume('k', 1, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(7, $denied->retryAfter, 'ceil(6.4)=7 — floor/round would give 6');
    }

    public function testSlidingAllowResetCeilsFractionalWait(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new SlidingWindowRateLimiter($clock);
        $limiter->consume('k', 5, 10);
        $clock->ns = 3_600_000_000;
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(7, $decision->resetAfter, 'ceil(6.4)=7 — floor/round would give 6');
    }

    public function testSlidingMaxKeysDefaultBoundary(): void
    {
        $limiter = new SlidingWindowRateLimiter($this->clockAt(0));
        for ($i = 0; $i < 10000; ++$i) {
            $limiter->consume('k' . $i, 1, 100000);
        }

        try {
            $limiter->consume('k10000', 1, 100000);
            self::fail('key 10001 must exhaust the default capacity');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('capacity exhausted', $e->getMessage());
        }
    }

    public function testTokenCostDefaultsToOne(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(4, $decision->remaining, 'default cost must be exactly 1');
    }

    public function testTokenCheckConsumesExactlyOneUnit(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        $decision = $limiter->check('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(4, $decision->remaining);
    }

    public function testTokenLimitOneBucketWorks(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        self::assertTrue($limiter->consume('k', 1, 10)->allowed);
        self::assertFalse($limiter->consume('k', 1, 10)->allowed);
    }

    public function testTokenOneSecondWindowIsUsable(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        self::assertTrue($limiter->consume('k', 5, 1)->allowed);
    }

    public function testTokenCostEqualToLimitIsAllowed(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        $decision = $limiter->consume('k', 5, 10, 5);
        self::assertTrue($decision->allowed, 'cost == limit must be admissible (bucket starts full)');
        self::assertSame(0, $decision->remaining);
    }

    public function testTokenDenyRemainingFloorsFractionalLevel(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        $clock->ns = 1_200_000_000;
        $denied = $limiter->consume('k', 5, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(0, $denied->remaining, 'floor(0.6)=0 — ceil/round would give 1');
        self::assertSame(1, $denied->retryAfter, 'ceil(0.4/0.5)=1');
        self::assertSame(9, $denied->resetAfter, 'ceil(8.8)=9 — floor would give 8');
    }

    public function testTokenDenyRetryCeilsFractionalDeficit(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        $clock->ns = 600_000_000;
        $denied = $limiter->consume('k', 5, 10);
        self::assertFalse($denied->allowed);
        self::assertSame(2, $denied->retryAfter, 'ceil(0.7/0.5)=2 — floor/round would give 1');
        self::assertSame(10, $denied->resetAfter, 'ceil(9.4)=10 — floor/round would give 9');
    }

    public function testTokenDenyNearFullBucket(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('k', 5, 10);
        }
        $clock->ns = 9_600_000_000;
        $denied = $limiter->consume('k', 5, 10, 5);
        self::assertFalse($denied->allowed, 'tokens 4.8 < cost 5');
        self::assertSame(4, $denied->remaining, 'floor(4.8)=4');
        self::assertSame(1, $denied->retryAfter, 'ceil(0.2/0.5)=1');
        self::assertSame(1, $denied->resetAfter, 'ceil(0.4)=1');
    }

    public function testTokenAllowUntilFullFractional(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock);
        $limiter->consume('k', 5, 10);
        // tokens = min(5, 4 + 1.4*0.5) = 4.7; after consuming 1 -> 3.7.
        $clock->ns = 1_400_000_000;
        $decision = $limiter->consume('k', 5, 10);
        self::assertTrue($decision->allowed);
        self::assertSame(3, $decision->remaining, 'floor(3.7)=3 — ceil/round would give 4');
        self::assertSame(3, $decision->resetAfter, 'ceil((5-3.7)/0.5)=3');
    }

    public function testTokenMaxKeysDefaultBoundary(): void
    {
        $limiter = new TokenBucketRateLimiter($this->clockAt(0));
        for ($i = 0; $i < 10000; ++$i) {
            $limiter->consume('k' . $i, 1, 100000);
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity exhausted');
        $limiter->consume('k10000', 1, 100000);
    }

    public function testTokenSweepBoundaryIdleExactlyOneWindow(): void
    {
        $clock = $this->clockAt(0);
        $limiter = new TokenBucketRateLimiter($clock, 1);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('a', 5, 10);
        }
        // EXACTLY one window idle: the key must be collectable so key "b"
        // fits — a strict > comparison here would keep the stale key.
        $clock->ns = 10 * self::SECOND;
        self::assertTrue($limiter->consume('b', 5, 10)->allowed);
    }

    public function testTokenSweepKeepsBucketsInsideTheirWindow(): void
    {
        $clock = $this->clockAt(8 * self::SECOND);
        $limiter = new TokenBucketRateLimiter($clock, 1);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume('a', 5, 10);
        }
        $clock->ns = 9 * self::SECOND;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity exhausted');
        $limiter->consume('b', 5, 10);
    }

    // ------------------------------------------------------------------
    // Tiered: boundary + fallback details
    // ------------------------------------------------------------------

    public function testTieredIdentityOfExactly512CharsIsAllowed(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $outcome = $tiered->evaluate(new RateLimitRule('api', 5, 60), str_repeat('x', 512));
        self::assertTrue($outcome->allowed);
    }

    public function testTieredCostUnawareLimiterMirrorsRetryAfterIntoResetAfter(): void
    {
        $tiered = new TieredRateLimiter(new InMemoryRateLimiter());
        $outcome = $tiered->evaluate(new RateLimitRule('api', 5, 60), 'alice');
        self::assertTrue($outcome->allowed);
        self::assertSame($outcome->retryAfter, $outcome->resetAfter, 'legacy 4-field decisions mirror retryAfter into resetAfter');
    }

    public function testTieredStorageKeySeparatorPreventsNameCollisions(): void
    {
        $tiered = new TieredRateLimiter(new SlidingWindowRateLimiter($this->clockAt(0)));
        $api = new RateLimitRule('api', 1, 60);
        $apil = new RateLimitRule('apil', 1, 60);
        self::assertTrue($tiered->evaluate($api, 'lice')->allowed, 'api>lice');
        self::assertTrue($tiered->evaluate($apil, 'ice')->allowed, 'apil>ice must NOT share the bucket of api>lice');
    }

    // ------------------------------------------------------------------
    // Rule/Outcome/Verdict boundary details
    // ------------------------------------------------------------------

    public function testRuleNameOfExactly64CharsIsAllowed(): void
    {
        $rule = new RateLimitRule(str_repeat('x', 64), 1, 1);
        self::assertSame(64, strlen($rule->name));
    }

    public function testRuleFromArrayWithoutMethodsKeepsNull(): void
    {
        $rule = RateLimitRule::fromArray(['name' => 'a', 'limit' => 1, 'windowSeconds' => 1]);
        self::assertNull($rule->methods);
    }

    public function testRuleFromArrayRejectsNonListMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list of strings');
        RateLimitRule::fromArray(['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => 'POST']);
    }

    public function testRuleFromArrayAssociativeMethodsMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list of strings');
        RateLimitRule::fromArray(['name' => 'a', 'limit' => 1, 'windowSeconds' => 1, 'methods' => ['POST' => 'x']]);
    }

    public function testOutcomeAcceptsZeroResetAfter(): void
    {
        $outcome = new RateLimitRuleOutcome('a', true, 1, 0, 0, 0);
        self::assertSame(0, $outcome->resetAfter);
    }

    /**
     * Fake nanosecond clock: advance by assigning `->ns`.
     */
    private function clockAt(int $ns): FakeRateClock
    {
        return new FakeRateClock($ns);
    }
}
