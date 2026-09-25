<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.27.0 Async Rules: concurrent rule evaluation on the
 * fiber scheduler.
 *
 * Deterministic tests over a fake monotonic clock + recording sleeper: verdict
 * construction, report aggregation, options validation, input-order reports,
 * concurrency caps, per-rule deadlines, fail-fast interruption (forced skip,
 * cooperative skip and graceful rule-authored verdicts), semaphore-parked
 * cancellation and the run() driver — so a mutant in the engine (order flips,
 * cap removal, deadline or cancellation mapping changes) cannot survive.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Rules\AsyncRuleEngine;
use Zef\Framework\Rules\RuleEngineOptions;
use Zef\Framework\Rules\RuleInterface;
use Zef\Framework\Rules\RuleReport;
use Zef\Framework\Rules\RuleVerdict;
use Zef\Framework\Rules\RuleVerdictStatus;
use Zef\Framework\Runtime\Async\CancellationTokenInterface;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\TaskCancelledException;

/**
 * @internal
 */
final class AsyncRulesV27Test extends TestCase
{
    private FakeAsyncClock $clock;

    private FakeAsyncSleeper $sleeper;

    private FiberScheduler $scheduler;

    private AsyncRuleEngine $engine;

    protected function setUp(): void
    {
        $this->clock = new FakeAsyncClock();
        $this->sleeper = new FakeAsyncSleeper($this->clock);
        $this->scheduler = new FiberScheduler($this->clock, $this->sleeper);
        $this->engine = new AsyncRuleEngine($this->scheduler);
    }

    // ------------------------------------------------------------------
    // RuleVerdict value object
    // ------------------------------------------------------------------

    public function testPassVerdictDefaults(): void
    {
        $verdict = RuleVerdict::pass();

        self::assertSame(RuleVerdictStatus::Passed, $verdict->status());
        self::assertSame('', $verdict->message());
        self::assertSame([], $verdict->metadata());
        self::assertNull($verdict->throwable());
        self::assertNull($verdict->ruleName());
        self::assertTrue($verdict->isPassed());
        self::assertFalse($verdict->isFailed());
        self::assertFalse($verdict->isSkipped());
    }

    public function testFailVerdictCarriesMessageAndMetadata(): void
    {
        $verdict = RuleVerdict::fail('quota exceeded', ['limit' => 10]);

        self::assertSame(RuleVerdictStatus::Failed, $verdict->status());
        self::assertSame('quota exceeded', $verdict->message());
        self::assertSame(['limit' => 10], $verdict->metadata());
        self::assertTrue($verdict->isFailed());
        self::assertNull($verdict->throwable());
    }

    public function testSkipVerdictCarriesReason(): void
    {
        $verdict = RuleVerdict::skip('not applicable to guests');

        self::assertSame(RuleVerdictStatus::Skipped, $verdict->status());
        self::assertSame('not applicable to guests', $verdict->message());
        self::assertTrue($verdict->isSkipped());
    }

    public function testFromThrowableVerdictFormatsMessageAndKeepsThrowable(): void
    {
        $original = new \RuntimeException('boom');
        $verdict = RuleVerdict::fromThrowable($original);

        self::assertTrue($verdict->isFailed());
        self::assertSame('RuntimeException: boom', $verdict->message());
        self::assertSame($original, $verdict->throwable());
    }

    public function testTimeoutVerdictFormatsDeadlineIntoMessage(): void
    {
        $verdict = RuleVerdict::timeout('slow', 1.0);

        self::assertTrue($verdict->isFailed());
        self::assertSame('rule "slow" exceeded its 1 second evaluation deadline', $verdict->message());
        self::assertSame(['timeout_seconds' => 1.0], $verdict->metadata());
        self::assertSame('slow', $verdict->ruleName());
        self::assertNull($verdict->throwable());
    }

    public function testForRuleAnnotatesNameAndPreservesEverythingElse(): void
    {
        $original = RuleVerdict::fail('nope', ['a' => 1]);
        $annotated = $original->forRule('quota');

        self::assertSame('quota', $annotated->ruleName());
        self::assertSame('nope', $annotated->message());
        self::assertSame(['a' => 1], $annotated->metadata());
        self::assertTrue($annotated->isFailed());
        // Immutable: the source verdict is untouched.
        self::assertNull($original->ruleName());
    }

    // ------------------------------------------------------------------
    // RuleReport value object
    // ------------------------------------------------------------------

    public function testReportPartitionsVerdictsByStatusAndCounts(): void
    {
        $report = new RuleReport([
            RuleVerdict::pass(),
            RuleVerdict::fail('x'),
            RuleVerdict::skip('y'),
            RuleVerdict::fail('z'),
        ]);

        self::assertSame(4, $report->count());
        self::assertFalse($report->allPassed());
        self::assertCount(1, $report->passed());
        self::assertCount(2, $report->failures());
        self::assertCount(1, $report->skipped());
        self::assertSame(RuleVerdictStatus::Failed, $report->failures()[0]->status());
    }

    public function testEmptyReportPassesVacuously(): void
    {
        $report = new RuleReport([]);

        self::assertSame(0, $report->count());
        self::assertTrue($report->allPassed());
        self::assertSame([], $report->verdicts());
        self::assertSame([], $report->failures());
    }

    // ------------------------------------------------------------------
    // RuleEngineOptions value object
    // ------------------------------------------------------------------

    public function testOptionsDefaultsAreUnlimitedAndLenient(): void
    {
        $options = new RuleEngineOptions();

        self::assertNull($options->concurrency());
        self::assertNull($options->perRuleTimeout());
        self::assertFalse($options->failFast());
    }

    public function testOptionsWithersReturnModifiedCopies(): void
    {
        $original = new RuleEngineOptions();
        $modified = $original
            ->withConcurrency(3)
            ->withPerRuleTimeout(0.5)
            ->withFailFast()
        ;

        self::assertSame(3, $modified->concurrency());
        self::assertSame(0.5, $modified->perRuleTimeout());
        self::assertTrue($modified->failFast());
        // Immutability: the original is untouched.
        self::assertNull($original->concurrency());
        self::assertNull($original->perRuleTimeout());
        self::assertFalse($original->failFast());
        // Wither accepts null to reset back to unlimited / no deadline.
        self::assertNull($modified->withConcurrency(null)->concurrency());
        self::assertNull($modified->withPerRuleTimeout(null)->perRuleTimeout());
        self::assertFalse($modified->withFailFast(false)->failFast());
    }

    public function testOptionsRejectInvalidConcurrency(): void
    {
        try {
            new RuleEngineOptions(concurrency: 0);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('rule engine concurrency must be >= 1 or null for unlimited, got 0.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new RuleEngineOptions(concurrency: -2);
    }

    public function testOptionsRejectNegativeTimeout(): void
    {
        try {
            new RuleEngineOptions(perRuleTimeout: -0.5);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('rule engine per-rule timeout must be >= 0 seconds or null for none, got -0.500000.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new RuleEngineOptions()->withPerRuleTimeout(-1.0);
    }

    // ------------------------------------------------------------------
    // Engine: basic evaluation semantics
    // ------------------------------------------------------------------

    public function testEvaluateCollectsVerdictsInInputOrderWithSubjectAndNames(): void
    {
        $seen = [];
        $report = $this->engine->run([
            $this->rule('alpha', function ($subject) use (&$seen): RuleVerdict {
                $seen['alpha'] = $subject;

                return RuleVerdict::pass();
            }),
            $this->rule('beta', function ($subject) use (&$seen): RuleVerdict {
                $seen['beta'] = $subject;

                return RuleVerdict::fail('nope');
            }),
            $this->rule('gamma', fn (): RuleVerdict => RuleVerdict::skip('n/a')),
        ], subject: ['id' => 7]);

        self::assertSame(3, $report->count());
        self::assertSame(['id' => 7], $seen['alpha']);
        self::assertSame(['id' => 7], $seen['beta']);

        $verdicts = $report->verdicts();
        self::assertSame(['alpha', 'beta', 'gamma'], array_map(static fn (RuleVerdict $v): ?string => $v->ruleName(), $verdicts));
        self::assertTrue($verdicts[0]->isPassed());
        self::assertTrue($verdicts[1]->isFailed());
        self::assertSame('nope', $verdicts[1]->message());
        self::assertTrue($verdicts[2]->isSkipped());
        self::assertTrue($report->allPassed() === false);
        self::assertCount(1, $report->failures());
    }

    public function testEmptyRuleSetShortCircuitsEvenOutsideCoroutine(): void
    {
        // No rules means no suspension, so evaluate() short-circuits before
        // the coroutine guard and run() simply wraps an empty report.
        $report = $this->engine->evaluate([]);

        self::assertSame(0, $report->count());
        self::assertTrue($report->allPassed());

        $driven = $this->engine->run([], subject: 'anything');
        self::assertSame(0, $driven->count());
        self::assertSame([], $driven->verdicts());
    }

    public function testEvaluateOutsideCoroutineThrowsGuidance(): void
    {
        try {
            $this->engine->evaluate([$this->rule('x', fn (): RuleVerdict => RuleVerdict::pass())]);
            self::fail('expected LogicException');
        } catch (\LogicException $exception) {
            self::assertSame(
                'AsyncRuleEngine::evaluate() must be called from inside a coroutine driven by its FiberScheduler; use AsyncRuleEngine::run() for a blocking top-level evaluation.',
                $exception->getMessage(),
            );
        }
    }

    public function testRunDrivesSchedulerAndReturnsReport(): void
    {
        $report = $this->engine->run([
            $this->rule('a', fn (): RuleVerdict => RuleVerdict::pass('ok')),
            $this->rule('b', fn (): RuleVerdict => RuleVerdict::fail('bad')),
        ]);

        self::assertSame(2, $report->count());
        self::assertFalse($report->allPassed());
    }

    public function testRunInsideRunningSchedulerPropagatesReentrancyError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the scheduler is already running');

        $this->scheduler->run(function (): void {
            $this->engine->run([$this->rule('x', fn (): RuleVerdict => RuleVerdict::pass())]);
        });
    }

    public function testInvalidRuleElementThrowsWithIndexAndType(): void
    {
        /** @var array<int, mixed> $invalid — deliberately contains non-rules */
        $invalid = [
            $this->rule('ok', fn (): RuleVerdict => RuleVerdict::pass()),
            'not-a-rule',
            null,
        ];

        try {
            $this->engine->run($invalid); // @phpstan-ignore argument.type (invalid elements are the scenario under test)
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('rule at index 1 must implement RuleInterface, got string.', $exception->getMessage());
        }
    }

    public function testEngineIsReusableAcrossSequentialRuns(): void
    {
        $rules = static fn (): array => [
            new class implements RuleInterface {
                #[\Override]
                public function name(): string
                {
                    return 'always-pass';
                }

                #[\Override]
                public function evaluate(mixed $subject, CancellationTokenInterface $cancellation): RuleVerdict
                {
                    return RuleVerdict::pass();
                }
            },
        ];

        $first = $this->engine->run($rules());
        $second = $this->engine->run($rules());

        self::assertTrue($first->allPassed());
        self::assertTrue($second->allPassed());
        self::assertSame(1, $second->count());
    }

    public function testConcurrencyCapLimitsParallelRuleExecution(): void
    {
        [$rules, $peak] = $this->concurrencyRules(4);

        $report = $this->engine->run($rules, options: new RuleEngineOptions(concurrency: 2));

        self::assertTrue($report->allPassed());
        self::assertSame(4, $report->count());
        self::assertSame(2, $peak(), 'peak parallelism must never exceed the semaphore permit count');
    }

    public function testUnlimitedConcurrencyLetsEveryRuleRunAtOnce(): void
    {
        [$rules, $peak] = $this->concurrencyRules(4);

        $report = $this->engine->run($rules);

        self::assertTrue($report->allPassed());
        self::assertSame(4, $peak(), 'unlimited concurrency is one permit per rule, so all rules overlap');
    }

    // ------------------------------------------------------------------
    // Engine: per-rule deadlines
    // ------------------------------------------------------------------

    public function testPerRuleDeadlineFailsSlowRuleButPassesFastSibling(): void
    {
        $report = $this->engine->run([
            $this->rule('fast', fn (): RuleVerdict => RuleVerdict::pass()),
            $this->rule('slow', function (): RuleVerdict {
                $this->scheduler->sleep(5.0);

                return RuleVerdict::pass();
            }),
        ], options: new RuleEngineOptions(perRuleTimeout: 1.0));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isPassed());
        self::assertTrue($verdicts[1]->isFailed());
        self::assertSame('rule "slow" exceeded its 1 second evaluation deadline', $verdicts[1]->message());
        self::assertSame(['timeout_seconds' => 1.0], $verdicts[1]->metadata());
        self::assertSame('slow', $verdicts[1]->ruleName());
    }

    public function testZeroDeadlineNeverInterruptsNonSuspendingRule(): void
    {
        // Cooperative guarantee: the non-suspending rule finishes its first
        // (and only) step before the due-now deadline timer ever fires.
        $report = $this->engine->run([
            $this->rule('snappy', fn (): RuleVerdict => RuleVerdict::pass('won the race')),
        ], options: new RuleEngineOptions(perRuleTimeout: 0.0));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isPassed(), 'non-suspending rule must win a 0-second deadline race');
        self::assertSame('won the race', $verdicts[0]->message());
    }

    public function testZeroDeadlineInterruptsSuspendingRule(): void
    {
        $report = $this->engine->run([
            $this->rule('sleeper', function (): RuleVerdict {
                $this->scheduler->sleep(1.0);

                return RuleVerdict::pass();
            }),
        ], options: new RuleEngineOptions(perRuleTimeout: 0.0));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('rule "sleeper" exceeded its 0 second evaluation deadline', $verdicts[0]->message());
    }

    public function testRuleThrowingTaskCancelledExceptionWithoutDeadlineIsReportedAsTimeout(): void
    {
        // Documented edge: TaskCancelledException escaping a rule is reserved
        // for engine-level cancellation; without a cancellation request the
        // only remaining canceller is the deadline guard, so it is reported
        // as a timeout (0 seconds when no deadline was configured).
        $report = $this->engine->run([
            $this->rule('rogue', fn (): RuleVerdict => throw new TaskCancelledException('self-initiated')),
        ]);

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('rule "rogue" exceeded its 0 second evaluation deadline', $verdicts[0]->message());
    }

    public function testRuleThrowingExceptionBecomesFailedVerdictAndTriggersFailFast(): void
    {
        $report = $this->engine->run([
            $this->rule('explodes', function (): RuleVerdict {
                throw new \RuntimeException('boom');
            }),
            $this->rule('in-flight', function (): RuleVerdict {
                $this->scheduler->sleep(10.0);

                return RuleVerdict::pass();
            }),
            $this->rule('parked', fn (): RuleVerdict => RuleVerdict::pass()),
        ], options: new RuleEngineOptions(concurrency: 2, failFast: true));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('RuntimeException: boom', $verdicts[0]->message());
        self::assertInstanceOf(\RuntimeException::class, $verdicts[0]->throwable());
        self::assertSame('boom', $verdicts[0]->throwable()?->getMessage());
        self::assertTrue($verdicts[1]->isSkipped(), 'a thrown exception is a failure, so fail-fast interrupts in-flight rules');
        self::assertTrue($verdicts[2]->isSkipped(), 'fail-fast also skips rules waiting for a permit');
    }

    public function testCompletedRuleCancelsItsDeadlineGuard(): void
    {
        $report = $this->engine->run([
            $this->rule('snappy', fn (): RuleVerdict => RuleVerdict::pass()),
        ], options: new RuleEngineOptions(perRuleTimeout: 1.0));

        self::assertTrue($report->allPassed());
        self::assertSame(0, $this->clock->nano, 'a settled rule must cancel its deadline guard: the pump must not idle-wait until it fires');
    }

    // ------------------------------------------------------------------
    // Engine: fail-fast
    // ------------------------------------------------------------------

    public function testFailFastSkipsQueuedSiblingAndInterruptsRunningOne(): void
    {
        $executed = ['queued' => false];
        $report = $this->engine->run([
            $this->rule('first-fails', function (): RuleVerdict {
                $this->scheduler->suspend();

                return RuleVerdict::fail('nope');
            }),
            $this->rule('running', function (): RuleVerdict {
                $this->scheduler->sleep(10.0);

                return RuleVerdict::pass();
            }),
            $this->rule('queued', function () use (&$executed): RuleVerdict {
                $executed['queued'] = true;

                return RuleVerdict::pass();
            }),
        ], options: new RuleEngineOptions(concurrency: 2, failFast: true));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('nope', $verdicts[0]->message());
        self::assertTrue($verdicts[1]->isSkipped(), 'suspended sibling must be interrupted into a skip');
        self::assertSame('rule "running" was cancelled by fail-fast', $verdicts[1]->message());
        self::assertTrue($verdicts[2]->isSkipped(), 'sibling waiting for a permit must be skipped before starting');
        self::assertFalse($executed['queued'], 'skipped-before-start rule must never execute');
    }

    public function testFailFastDoesNotFireOnSkippedOrPassedVerdicts(): void
    {
        $completed = ['sibling' => false];
        $report = $this->engine->run([
            $this->rule('skips-itself', fn (): RuleVerdict => RuleVerdict::skip('n/a')),
            $this->rule('completes', function () use (&$completed): RuleVerdict {
                $this->scheduler->suspend();
                $completed['sibling'] = true;

                return RuleVerdict::pass();
            }),
        ], options: new RuleEngineOptions(failFast: true));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isSkipped());
        self::assertTrue($verdicts[1]->isPassed());
        self::assertTrue($completed['sibling'], 'skip verdicts must not trigger fail-fast');
    }

    public function testTimedOutRuleTriggersFailFast(): void
    {
        $queued = ['ran' => false];
        $report = $this->engine->run([
            $this->rule('slow', function (): RuleVerdict {
                $this->scheduler->sleep(5.0);

                return RuleVerdict::pass();
            }),
            $this->rule('after', function () use (&$queued): RuleVerdict {
                $queued['ran'] = true;

                return RuleVerdict::pass();
            }),
        ], options: new RuleEngineOptions(concurrency: 1, perRuleTimeout: 1.0)->withFailFast());

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertStringContainsString('exceeded its 1 second evaluation deadline', $verdicts[0]->message());
        self::assertTrue($verdicts[1]->isSkipped(), 'deadline miss is a failure, so fail-fast fires');
        self::assertFalse($queued['ran']);
    }

    public function testFailFastCancelsRulesParkedOnConcurrencySemaphore(): void
    {
        $report = $this->engine->run([
            $this->rule('fails', fn (): RuleVerdict => RuleVerdict::fail('nope')),
            $this->rule('parked-a', fn (): RuleVerdict => RuleVerdict::pass()),
            $this->rule('parked-b', fn (): RuleVerdict => RuleVerdict::pass()),
        ], options: new RuleEngineOptions(concurrency: 1, failFast: true));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertTrue($verdicts[1]->isSkipped(), 'semaphore-parked sibling must be cancelled into a skip');
        self::assertTrue($verdicts[2]->isSkipped());
        // A clean settle also proves permit accounting stayed balanced (no
        // over-release LogicException, no phantom waiter left behind).
    }

    public function testGracefulRuleCatchesCancellationAndReturnsItsOwnVerdict(): void
    {
        $report = $this->engine->run([
            $this->rule('first-fails', function (): RuleVerdict {
                $this->scheduler->suspend();

                return RuleVerdict::fail('nope');
            }),
            $this->rule('graceful', function ($subject, $token): RuleVerdict {
                try {
                    while (!$token->isCancelled()) {
                        $this->scheduler->suspend();
                    }
                } catch (TaskCancelledException) {
                    // Cooperative cancellation arrived mid-suspension.
                }

                return RuleVerdict::skip('observed cancellation');
            }),
        ], options: new RuleEngineOptions(failFast: true));

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isFailed());
        self::assertTrue($verdicts[1]->isSkipped());
        self::assertSame('observed cancellation', $verdicts[1]->message(), 'the rule-authored verdict must survive fail-fast');
        self::assertSame('graceful', $verdicts[1]->ruleName());
    }

    public function testCancellationTokenReachesRulesAndReportsCancellation(): void
    {
        $report = $this->engine->run([
            $this->rule('observer', fn ($subject, $token): RuleVerdict => RuleVerdict::pass('cancelled=' . ($token->isCancelled() ? 'true' : 'false'))),
        ]);

        $verdicts = $report->verdicts();
        self::assertTrue($verdicts[0]->isPassed());
        self::assertSame('cancelled=false', $verdicts[0]->message());
    }

    // ------------------------------------------------------------------
    // Engine: verdict preservation under failing fail-fast bookkeeping
    // ------------------------------------------------------------------

    public function testRecordedVerdictSurvivesThrowingTokenCallback(): void
    {
        // A hostile rule registers a throwing token callback; the bomb fires
        // synchronously inside cts->cancel() during fail-fast bookkeeping —
        // AFTER the rule's verdict was already recorded. The report must
        // keep the authored verdict, not replace it with the bookkeeping
        // failure.
        $report = $this->engine->run([
            $this->rule('hostile', function ($subject, $token): RuleVerdict {
                $token->register(static fn (): never => throw new \LogicException('callback bomb'));

                return RuleVerdict::fail('normal failure');
            }),
            $this->rule('bystander', fn (): RuleVerdict => RuleVerdict::pass()),
        ], options: new RuleEngineOptions(failFast: true));

        $verdicts = $report->verdicts();
        self::assertSame(2, $report->count());
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('normal failure', $verdicts[0]->message(), 'recorded verdict must never be clobbered');
        self::assertTrue($verdicts[1]->isPassed());
    }

    public function testRecordedVerdictSurvivesThrowingTokenCallbackWithCancellation(): void
    {
        // Same bomb, but thrown as TaskCancelledException — the await loop's
        // cancellation branch must behave identically: verdict preserved.
        $report = $this->engine->run([
            $this->rule('hostile', function ($subject, $token): RuleVerdict {
                $token->register(static fn (): never => throw new TaskCancelledException('callback bomb'));

                return RuleVerdict::fail('authored failure');
            }),
            $this->rule('bystander', fn (): RuleVerdict => RuleVerdict::pass()),
        ], options: new RuleEngineOptions(failFast: true));

        $verdicts = $report->verdicts();
        self::assertSame(2, $report->count());
        self::assertTrue($verdicts[0]->isFailed());
        self::assertSame('authored failure', $verdicts[0]->message());
        self::assertTrue($verdicts[1]->isPassed());
    }

    /**
     * Builds an anonymous rule whose evaluate() delegates to the closure.
     *
     * @param \Closure(mixed, CancellationTokenInterface): RuleVerdict $evaluate
     */
    private function rule(string $name, \Closure $evaluate): RuleInterface
    {
        return new readonly class($name, $evaluate) implements RuleInterface {
            public function __construct(
                private string $name,
                private \Closure $evaluate,
            ) {}

            #[\Override]
            public function name(): string
            {
                return $this->name;
            }

            #[\Override]
            public function evaluate(mixed $subject, CancellationTokenInterface $cancellation): RuleVerdict
            {
                $verdict = ($this->evaluate)($subject, $cancellation);

                if (!$verdict instanceof RuleVerdict) {
                    throw new \LogicException('test rule closures must return a RuleVerdict');
                }

                return $verdict;
            }
        };
    }

    // ------------------------------------------------------------------
    // Engine: concurrency cap
    // ------------------------------------------------------------------

    /**
     * Tracks peak parallelism with rules that yield once mid-evaluation.
     *
     * @return array{0: list<RuleInterface>, 1: \Closure(): int}
     */
    private function concurrencyRules(int $count): array
    {
        $active = 0;
        $peak = 0;
        $rules = [];

        for ($i = 0; $i < $count; ++$i) {
            $rules[] = $this->rule("r{$i}", function () use (&$active, &$peak): RuleVerdict {
                ++$active;
                $peak = max($peak, $active);
                $this->scheduler->suspend();
                --$active;

                return RuleVerdict::pass();
            });
        }

        return [$rules, static function () use (&$peak): int { return $peak; }];
    }
}
