<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: ports)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

/**
 * Port for concurrent rule evaluation on an async runtime.
 *
 * Implementations run every rule as its own coroutine, honour the options
 * (concurrency cap, per-rule deadline, fail-fast) and always return a total
 * RuleReport: one verdict per input rule, in input order. Evaluation never
 * throws for rule-level problems — throwables become failed verdicts.
 */
interface AsyncRuleEngineInterface
{
    /**
     * Evaluates all rules against $subject. Must be called from inside a
     * coroutine driven by the implementation's scheduler (it suspends while
     * rules run); use run() for a blocking top-level evaluation.
     *
     * @param iterable<RuleInterface> $rules
     */
    public function evaluate(iterable $rules, mixed $subject = null, ?RuleEngineOptions $options = null): RuleReport;

    /**
     * Blocking convenience driver: wraps evaluate() in a full scheduler run
     * and returns the report once every rule settled.
     *
     * @param iterable<RuleInterface> $rules
     */
    public function run(iterable $rules, mixed $subject = null, ?RuleEngineOptions $options = null): RuleReport;
}
