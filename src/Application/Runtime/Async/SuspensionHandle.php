<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal reservation ticket for one fiber suspension.
 *
 * Blocking primitives (channels, semaphores, wait groups, timers, await)
 * obtain a handle from the scheduler before suspending, register it with
 * whatever will eventually resolve the wait, and resume when the handle is
 * either delivered a value or failed with a throwable. Handles settle at
 * most once; later deliver()/fail() calls are ignored, which makes wake-up
 * races harmless. onSettle() hooks let primitives splice their queue entry
 * out when a suspension ends abnormally (cancellation, channel close).
 */
final class SuspensionHandle
{
    private bool $settled = false;

    /**
     * @var list<\Closure(): void>
     */
    private array $settleHooks = [];

    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly FiberTask $task,
    ) {}

    /** The task whose coroutine owns this suspension. */
    public function owner(): FiberTask
    {
        return $this->task;
    }

    /** True once the suspension was resolved (value or failure). */
    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * Registers a hook fired exactly once when the handle settles (before
     * the fiber is re-queued). Registering on an already-settled handle
     * fires the hook immediately.
     */
    public function onSettle(\Closure $hook): void
    {
        if ($this->settled) {
            $hook();

            return;
        }

        $this->settleHooks[] = $hook;
    }

    /** Resolves the suspension with a value; ignored when already settled. */
    public function deliver(mixed $value): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->runSettleHooks();
        $this->scheduler->enqueueResume($this->task, new SuspendValue($value));
    }

    /**
     * Resolves the suspension with a failure; the throwable is rethrown
     * inside the suspended fiber at the suspension point. Ignored when
     * already settled.
     */
    public function fail(\Throwable $reason): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->runSettleHooks();
        $this->scheduler->enqueueResume($this->task, new SuspendFail($reason));
    }

    private function runSettleHooks(): void
    {
        $hooks = $this->settleHooks;
        $this->settleHooks = [];

        foreach ($hooks as $hook) {
            $hook();
        }
    }
}
