<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Counting semaphore for bounding coroutine concurrency.
 *
 * acquire() consumes a permit immediately when one is free, otherwise parks
 * the coroutine until release() hands a permit over. Used to cap fan-out
 * (e.g. at most N concurrent downloads). release() guards against
 * over-release with a LogicException, so a bookkeeping bug in a coroutine
 * cannot inflate the permit pool silently.
 */
final class Semaphore
{
    private int $available;

    /**
     * @var list<SuspensionHandle>
     */
    private array $waiters = [];

    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $permits,
    ) {
        if ($permits < 1) {
            throw new \InvalidArgumentException(sprintf('Semaphore permit count must be >= 1, got %d.', $permits));
        }

        $this->available = $permits;
    }

    /** Non-blocking acquisition attempt. */
    public function tryAcquire(): bool
    {
        if ($this->available === 0) {
            return false;
        }

        --$this->available;

        return true;
    }

    /**
     * Acquires a permit, parking the coroutine while none is free.
     * An optional cancellation token aborts the wait with
     * TaskCancelledException; a token cancelled before the call fails
     * immediately without parking.
     */
    public function acquire(?CancellationTokenInterface $cancellation = null): void
    {
        if ($cancellation instanceof CancellationTokenInterface) {
            $cancellation->throwIfCancelled();
        }

        if ($this->tryAcquire()) {
            return;
        }

        $handle = $this->scheduler->beginSuspension('semaphore acquire');
        $this->waiters[] = $handle;
        $handle->onSettle(function () use ($handle): void {
            $this->waiters = array_values(array_filter(
                $this->waiters,
                static fn (SuspensionHandle $pending): bool => $pending !== $handle,
            ));
        });

        if ($cancellation instanceof CancellationTokenInterface) {
            // The callback stays registered on purpose: after this suspension
            // settles, a late fail() on the settled handle is ignored, so the
            // callback is a provable no-op once the acquisition finished.
            $cancellation->register(function () use ($handle): void {
                $handle->fail(new TaskCancelledException('semaphore acquisition cancelled by token'));
            });
        }

        $this->scheduler->awaitSuspension($handle);
    }

    /**
     * Returns a permit and wakes the oldest parked acquirer (the permit is
     * handed straight to it). Over-release is a bookkeeping bug and throws.
     */
    public function release(): void
    {
        if ($this->available >= $this->permits) {
            throw new \LogicException('semaphore over-release: more release() calls than acquired permits');
        }

        ++$this->available;

        if ($this->waiters !== []) {
            $waiter = array_shift($this->waiters);
            --$this->available;
            $waiter->deliver(null);
        }
    }
}
