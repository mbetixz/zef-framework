<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Lifecycle states of an async task.
 *
 * The transition graph is linear per run: Pending -> Running -> one of the
 * three terminal states (Succeeded, Failed, Cancelled). Terminal states are
 * permanent — no state may be observed twice or revisited afterwards.
 */
enum TaskState
{
    /**
     * Spawned but not yet started: the scheduler has queued the task and the
     * fiber does not exist yet (or, for timers, the deadline has not fired).
     */
    case Pending;

    /** The fiber exists and has not terminated; it may be suspended. */
    case Running;

    /** The coroutine returned normally; result() carries the return value. */
    case Succeeded;

    /** The coroutine threw; result() rethrows and throwable() exposes it. */
    case Failed;

    /** The task was cancelled before completing; result() always throws. */
    case Cancelled;

    /**
     * True for Succeeded, Failed and Cancelled — states that can no longer
     * change and whose result/throwable are final.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Running => false,
            self::Succeeded, self::Failed, self::Cancelled => true,
        };
    }
}
