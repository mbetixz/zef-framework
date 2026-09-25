<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Handle over one coroutine spawned on the async scheduler.
 *
 * The handle is the only sanctioned observation surface: state inspection,
 * cooperative cancellation and result retrieval. Waiting for completion is
 * the scheduler's job (FiberScheduler::await()), not the handle's — the
 * handle stays usable from inside or outside a coroutine.
 */
interface TaskInterface
{
    /** Monotonic per-scheduler identifier (main task is 1, then 2, 3, ...). */
    public function id(): int;

    /** Auto-assigned "task-N" or the caller-provided spawn/delay label. */
    public function name(): string;

    /** Current lifecycle state; see TaskState for the transition graph. */
    public function state(): TaskState;

    /** True once a terminal state (Succeeded, Failed, Cancelled) was reached. */
    public function isDone(): bool;

    /**
     * Requests cooperative cancellation. Returns true when this call moved
     * the task into (or towards) the Cancelled state; false when the task was
     * already done or cancelled before. A running coroutine observes the
     * cancellation at its next suspension point as TaskCancelledException.
     */
    public function cancel(): bool;

    /**
     * Returns the coroutine's return value once Succeeded. Failed tasks
     * rethrow the original throwable, cancelled tasks throw
     * TaskCancelledException, and non-terminal tasks throw \LogicException.
     */
    public function result(): mixed;

    /** The failure reason for Failed tasks; null for every other state. */
    public function throwable(): ?\Throwable;
}
