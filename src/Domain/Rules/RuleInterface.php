<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: ports)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

use Zef\Framework\Runtime\Async\CancellationTokenInterface;

/**
 * One checkable rule evaluated against a subject.
 *
 * A rule is a named predicate that returns a verdict instead of throwing:
 * pass means the check held, fail means it did not, skip means the rule
 * decided it does not apply to this subject. Rules that suspend (via the
 * scheduler driving the engine) get true concurrency — they must suspend on
 * the SAME scheduler instance the engine was built with; suspending on a
 * foreign scheduler fails loudly with a LogicException by construction.
 *
 * Cancellation contract:
 * - $cancellation is the engine's cooperative signal. A rule may observe it
 *   between expensive steps (isCancelled()/throwIfCancelled()) to abandon
 *   work early and return RuleVerdict::skip() voluntarily.
 * - TaskCancelledException escaping evaluate() is RESERVED for engine-level
 *   cancellation (fail-fast or an evaluation deadline); the engine reports it
 *   as a skipped/timeout verdict respectively. Rules must never throw it for
 *   their own purposes.
 * - Any other throwable escaping evaluate() is recorded as a failed verdict
 *   carrying the original throwable — evaluation is total, one verdict per
 *   rule, always.
 */
interface RuleInterface
{
    /** Stable identifier used in task labels, verdict annotations and messages. */
    public function name(): string;

    /**
     * Evaluates the rule against $subject under the given cancellation view.
     * Runs inside a coroutine spawned by the engine, so suspending calls
     * (scheduler->sleep(), channel waits, ...) are allowed and concurrent.
     */
    public function evaluate(mixed $subject, CancellationTokenInterface $cancellation): RuleVerdict;
}
