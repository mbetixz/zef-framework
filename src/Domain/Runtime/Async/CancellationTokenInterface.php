<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Read-only view over a cancellation request (see CancellationTokenSource).
 *
 * Tokens are handed to coroutines and blocking primitives; they can observe
 * and react to a cancellation but can never trigger one. Callbacks registered
 * here fire synchronously, in registration order, at cancel() time.
 */
interface CancellationTokenInterface
{
    /** True once the owning source was cancelled. */
    public function isCancelled(): bool;

    /**
     * Throws TaskCancelledException when the source was already cancelled;
     * a cheap guard for loops and pre-suspension checks.
     *
     * @throws TaskCancelledException
     */
    public function throwIfCancelled(): void;

    /**
     * Registers a callback fired when the source is cancelled (immediately if
     * it already is). The return value is an unregister closure; calling it
     * more than once is harmless.
     */
    public function register(callable $callback): \Closure;
}
