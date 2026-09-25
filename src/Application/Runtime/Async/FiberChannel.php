<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Bounded FIFO channel for coroutine-to-coroutine communication (CSP-style).
 *
 * The channel is the async runtime's meeting point: senders park when the
 * buffer is full, receivers park when it is empty, and both sides wake up
 * through the scheduler. Invariants that make the behaviour predictable:
 * - at most one side (senders OR receivers) is ever parked — a parked
 *   receiver exists only while the buffer is empty AND no sender is parked,
 *   and vice versa;
 * - values keep strict FIFO order across buffer and handoff paths;
 * - close() is idempotent, fails everyone still parked, and leaves
 *   surviving buffered values receivable until the buffer drains;
 * - send()/receive() on a provably-dead channel raise
 *   ChannelClosedException instead of hanging or silently dropping.
 */
final class FiberChannel
{
    /**
     * @var list<mixed>
     */
    private array $buffer = [];

    private bool $closed = false;

    /**
     * @var list<SuspensionHandle>
     */
    private array $waitingReceivers = [];

    /**
     * Parked senders with the value they are trying to hand over.
     *
     * @var list<array{handle: SuspensionHandle, value: mixed}>
     */
    private array $waitingSenders = [];

    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $capacity = 1,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException(sprintf('Channel capacity must be >= 1, got %d.', $capacity));
        }
    }

    /**
     * Sends a value: direct handoff to a parked receiver, buffered while
     * there is room, otherwise parks the sender until a receiver takes the
     * value. Throws ChannelClosedException on a closed channel; a parked
     * sender that gets cancelled loses its value (documented cooperative
     * semantics) and rethrows TaskCancelledException.
     */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new ChannelClosedException('cannot send: the channel is closed');
        }

        if ($this->waitingReceivers !== []) {
            $receiver = array_shift($this->waitingReceivers);
            $receiver->deliver($value);

            return;
        }

        if (count($this->buffer) < $this->capacity) {
            $this->buffer[] = $value;

            return;
        }

        $handle = $this->scheduler->beginSuspension('channel send');
        $this->waitingSenders[] = ['handle' => $handle, 'value' => $value];
        $this->spliceOnSettle($handle, 'senders');
        $this->scheduler->awaitSuspension($handle);
    }

    /**
     * Receives a value in FIFO order: buffered values first, then direct
     * handoff from a parked sender, otherwise parks the receiver. After
     * close() the surviving buffered values remain receivable; once the
     * channel is provably empty, ChannelClosedException is raised.
     */
    public function receive(): mixed
    {
        if ($this->buffer !== []) {
            $value = array_shift($this->buffer);
            $this->admitOneSender();

            return $value;
        }

        // Parked senders are admitted as the buffer frees (admitOneSender),
        // so a parked sender with an empty buffer is impossible by the park
        // invariant: receivers only park on an empty, sender-less channel.

        if ($this->closed) {
            throw new ChannelClosedException('cannot receive: the channel is closed and drained');
        }

        $handle = $this->scheduler->beginSuspension('channel receive');
        $this->waitingReceivers[] = $handle;
        $this->spliceOnSettle($handle, 'receivers');

        return $this->scheduler->awaitSuspension($handle);
    }

    /**
     * Closes the channel idempotently. Parked receivers and parked senders
     * are failed (ChannelClosedException); buffered values are NOT
     * discarded — receive() drains them before the closed-and-drained
     * error fires. (By the park invariant, receivers can only be parked
     * while the buffer is empty, so there is nothing to hand them here.).
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->waitingReceivers as $receiver) {
            $receiver->fail(new ChannelClosedException('cannot receive: the channel was closed while waiting'));
        }

        $this->waitingReceivers = [];

        foreach ($this->waitingSenders as $sender) {
            $sender['handle']->fail(new ChannelClosedException('cannot send: the channel was closed while waiting'));
        }

        $this->waitingSenders = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Number of values currently buffered (parked coroutines excluded). */
    public function count(): int
    {
        return count($this->buffer);
    }

    private function admitOneSender(): void
    {
        if ($this->waitingSenders === []) {
            return;
        }

        $sender = array_shift($this->waitingSenders);
        $this->buffer[] = $sender['value'];
        $sender['handle']->deliver(null);
    }

    /**
     * Splices the handle's queue entry out when the suspension settles
     * abnormally (cancellation), so stale parked entries can never swallow
     * a future value.
     *
     * @param 'receivers'|'senders' $queue
     */
    private function spliceOnSettle(SuspensionHandle $handle, string $queue): void
    {
        $handle->onSettle(function () use ($handle, $queue): void {
            if ($queue === 'senders') {
                $this->waitingSenders = array_values(array_filter(
                    $this->waitingSenders,
                    static fn (array $entry): bool => $entry['handle'] !== $handle,
                ));

                return;
            }

            $this->waitingReceivers = array_values(array_filter(
                $this->waitingReceivers,
                static fn (SuspensionHandle $pending): bool => $pending !== $handle,
            ));
        });
    }
}
