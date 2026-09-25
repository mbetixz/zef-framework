<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: value objects)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

/**
 * Lifecycle outcome of one rule evaluation — mirrors TaskState's style:
 * three terminal statuses, no transitions, decided exactly once.
 */
enum RuleVerdictStatus
{
    /** The rule ran and its check held. */
    case Passed;

    /** The rule ran and its check did not hold (or it threw). */
    case Failed;

    /** The rule did not produce a judgement (not applicable or cancelled). */
    case Skipped;
}
