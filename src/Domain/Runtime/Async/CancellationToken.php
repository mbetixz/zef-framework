<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Immutable read-only token bound to exactly one CancellationTokenSource.
 *
 * The class is intentionally separate from the source: coroutines receive
 * this view and cannot cast it back into a cancellation trigger. All
 * behaviour delegates to the source's callback registry, so cancel()
 * semantics (synchronous, registration-ordered, once-only) live in one place.
 */
final readonly class CancellationToken implements CancellationTokenInterface
{
    /**
     * @internal instantiated only by CancellationTokenSource
     *
     * @param \Closure(): bool                            $isCancelled   source-backed state probe
     * @param \Closure(callable): \Closure                $register      source-backed callback registry
     * @param \Closure(): TaskCancelledException $exceptionFactory per-throw message factory
     */
    public function __construct(
        private \Closure $isCancelled,
        private \Closure $register,
        private \Closure $exceptionFactory,
    ) {}

    #[\Override]
    public function isCancelled(): bool
    {
        return ($this->isCancelled)();
    }

    #[\Override]
    public function throwIfCancelled(): void
    {
        if (($this->isCancelled)()) {
            throw ($this->exceptionFactory)();
        }
    }

    #[\Override]
    public function register(callable $callback): \Closure
    {
        return ($this->register)($callback);
    }
}
