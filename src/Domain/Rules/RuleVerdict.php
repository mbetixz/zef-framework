<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: value objects)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

/**
 * Immutable outcome of evaluating one rule.
 *
 * A verdict is total: it always carries exactly one status plus a human
 * readable message. Failed verdicts may carry the original throwable and
 * arbitrary structured metadata; skipped verdicts carry the reason. The
 * engine annotates every verdict with its rule name (forRule()), so a
 * verdict pulled out of a RuleReport is self-describing in logs.
 */
final readonly class RuleVerdict
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        private RuleVerdictStatus $status,
        private string $message,
        private array $metadata = [],
        private ?\Throwable $throwable = null,
        private ?string $ruleName = null,
    ) {}

    /** The check held. An optional message refines the outcome.
     *
     * @param array<string, mixed> $metadata
     */
    public static function pass(string $message = '', array $metadata = []): self
    {
        return new self(RuleVerdictStatus::Passed, $message, $metadata);
    }

    /** The check did not hold. The message explains what was violated.
     *
     * @param array<string, mixed> $metadata
     */
    public static function fail(string $message, array $metadata = []): self
    {
        return new self(RuleVerdictStatus::Failed, $message, $metadata);
    }

    /** The rule decided it does not apply to this subject.
     *
     * @param array<string, mixed> $metadata
     */
    public static function skip(string $reason, array $metadata = []): self
    {
        return new self(RuleVerdictStatus::Skipped, $reason, $metadata);
    }

    /**
     * A failed verdict produced by an escaping throwable: the message is
     * "class: message" so log lines never lose the exception origin, and the
     * original throwable stays reachable for callers that need a stack trace.
     *
     * @param array<string, mixed> $metadata
     */
    public static function fromThrowable(\Throwable $throwable, array $metadata = []): self
    {
        return new self(
            RuleVerdictStatus::Failed,
            sprintf('%s: %s', $throwable::class, $throwable->getMessage()),
            $metadata,
            $throwable,
        );
    }

    /**
     * A failed verdict for a rule whose evaluation deadline won the race.
     * Only the engine produces this — the seconds are always the configured
     * per-rule deadline that was exceeded.
     */
    public static function timeout(string $ruleName, float $seconds): self
    {
        return new self(
            RuleVerdictStatus::Failed,
            sprintf('rule "%s" exceeded its %.6g second evaluation deadline', $ruleName, $seconds),
            ['timeout_seconds' => $seconds],
            null,
            $ruleName,
        );
    }

    /** Returns a copy annotated with the authoritative rule name. */
    public function forRule(string $ruleName): self
    {
        return new self($this->status, $this->message, $this->metadata, $this->throwable, $ruleName);
    }

    public function status(): RuleVerdictStatus
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** The throwable behind a failed verdict; null for every other source. */
    public function throwable(): ?\Throwable
    {
        return $this->throwable;
    }

    /** The rule this verdict belongs to; null until the engine annotates it. */
    public function ruleName(): ?string
    {
        return $this->ruleName;
    }

    public function isPassed(): bool
    {
        return $this->status === RuleVerdictStatus::Passed;
    }

    public function isFailed(): bool
    {
        return $this->status === RuleVerdictStatus::Failed;
    }

    public function isSkipped(): bool
    {
        return $this->status === RuleVerdictStatus::Skipped;
    }
}
