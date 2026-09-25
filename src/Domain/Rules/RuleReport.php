<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: value objects)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

/**
 * Aggregate of one engine evaluation: exactly one verdict per input rule,
 * in input order. The report is the only result surface — task handles,
 * guards and cancellation machinery stay engine-internal.
 *
 * Semantics: allPassed() is false if and only if at least one verdict is
 * Failed. Skipped rules are not failures, and an empty rule set passes
 * vacuously.
 */
final readonly class RuleReport
{
    /**
     * @param list<RuleVerdict> $verdicts
     */
    public function __construct(private array $verdicts) {}

    /**
     * Verdicts in input rule order.
     *
     * @return list<RuleVerdict>
     */
    public function verdicts(): array
    {
        return $this->verdicts;
    }

    /** Number of rules evaluated (one verdict per rule). */
    public function count(): int
    {
        return count($this->verdicts);
    }

    /** True when no verdict is Failed — skipped rules do not veto. */
    public function allPassed(): bool
    {
        return array_all($this->verdicts, fn (RuleVerdict $verdict): bool => !$verdict->isFailed());
    }

    /**
     * @return list<RuleVerdict>
     */
    public function passed(): array
    {
        return $this->byStatus(RuleVerdictStatus::Passed);
    }

    /**
     * @return list<RuleVerdict>
     */
    public function failures(): array
    {
        return $this->byStatus(RuleVerdictStatus::Failed);
    }

    /**
     * @return list<RuleVerdict>
     */
    public function skipped(): array
    {
        return $this->byStatus(RuleVerdictStatus::Skipped);
    }

    /**
     * @return list<RuleVerdict>
     */
    private function byStatus(RuleVerdictStatus $status): array
    {
        $matched = [];

        foreach ($this->verdicts as $verdict) {
            if ($verdict->status() === $status) {
                $matched[] = $verdict;
            }
        }

        return $matched;
    }
}
