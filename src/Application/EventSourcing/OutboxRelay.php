<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Event\EventBusInterface;

/**
 * Relays due outbox entries onto the event bus and records the outcome.
 *
 * Per entry:
 * - dispatch succeeds → {@see OutboxStoreInterface::markProcessed()};
 * - dispatch throws   → attempts+1; below `maxAttempts` the entry stays
 *   pending with an exponential backoff (`base * 2^(attempt-1)`, capped),
 *   otherwise it is dead-lettered via {@see OutboxStoreInterface::markDead()}.
 *
 * Delivery is at-least-once: a listener crash after side effects but before
 * the commit replays the entry — consumers must be idempotent.
 */
final readonly class OutboxRelay
{
    public const int DEFAULT_MAX_ATTEMPTS = 5;
    public const int DEFAULT_BACKOFF_BASE_MS = 1_000;
    public const int DEFAULT_BACKOFF_CAP_MS = 60_000;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param OutboxStoreInterface  $outbox        outbox port
     * @param EventBusInterface     $bus           target bus (OutboxMessage dispatch)
     * @param null|(\Closure(): int) $clock         now source in nanoseconds (default: realtime)
     * @param int                   $maxAttempts   >= 1 — dispatch tries before dead-lettering
     * @param int                   $backoffBaseMs >= 1 — first retry delay
     * @param int                   $backoffCapMs  >= backoffBaseMs — retry delay ceiling
     */
    public function __construct(
        private OutboxStoreInterface $outbox,
        private EventBusInterface $bus,
        ?\Closure $clock = null,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private int $backoffBaseMs = self::DEFAULT_BACKOFF_BASE_MS,
        private int $backoffCapMs = self::DEFAULT_BACKOFF_CAP_MS,
    ) {
        if ($maxAttempts < 1) {
            throw new EventSourcingException("maxAttempts must be >= 1 (got {$maxAttempts}).");
        }
        if ($backoffBaseMs < 1) {
            throw new EventSourcingException("backoffBaseMs must be >= 1 (got {$backoffBaseMs}).");
        }
        if ($backoffCapMs < $backoffBaseMs) {
            throw new EventSourcingException(
                "backoffCapMs ({$backoffCapMs}) must be >= backoffBaseMs ({$backoffBaseMs}).",
            );
        }
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Relay up to $limit due entries.
     *
     * @param int $limit >= 1
     *
     * @return int number of entries successfully dispatched and marked processed
     */
    public function relay(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new EventSourcingException("relay() limit must be >= 1 (got {$limit}).");
        }
        $now = ($this->clock)();
        $entries = $this->outbox->due($limit, $now);
        $processed = 0;
        foreach ($entries as $entry) {
            try {
                $this->bus->dispatch(new OutboxMessage($entry));
            } catch (\Throwable $error) {
                $this->recordFailure($entry, $error, $now);

                continue;
            }
            $this->outbox->markProcessed($entry->id);
            ++$processed;
        }

        return $processed;
    }

    /**
     * Dead letters (status failed), most urgent first.
     *
     * @return list<OutboxEntry>
     */
    public function deadLetters(int $limit = 100): array
    {
        return $this->outbox->failed($limit);
    }

    /**
     * Give dead letters a fresh retry budget (v2.23.0): status back to
     * pending, attempts reset to 0, eligible on the next {@see relay()}.
     * Use after fixing the root cause; the original error stays visible on
     * each entry until its next dispatch attempt.
     *
     * Requeued entries are STAGGERED one millisecond apart (per position in
     * the batch) so a requeued herd re-enters the downstream spread out
     * instead of all at once.
     *
     * @param int $limit >= 1
     *
     * @return int number of entries requeued
     */
    public function requeueDeadLetters(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new EventSourcingException("requeueDeadLetters() limit must be >= 1 (got {$limit}).");
        }
        $now = ($this->clock)();
        $requeued = 0;
        foreach ($this->outbox->failed($limit) as $entry) {
            $this->outbox->requeue($entry->id, $now + $requeued * 1_000_000);
            ++$requeued;
        }

        return $requeued;
    }

    /**
     * Exponential backoff for the 1-based attempt number:
     * `base * 2^(attempt-1)`, capped at backoffCapMs.
     */
    public function retryDelayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new EventSourcingException("attempt must be >= 1 (got {$attempt}).");
        }
        $delay = $this->backoffBaseMs;
        for ($i = 1; $i < $attempt && $delay < $this->backoffCapMs; ++$i) {
            $delay *= 2;
            if ($delay >= $this->backoffCapMs) {
                return $this->backoffCapMs;
            }
        }

        return min($delay, $this->backoffCapMs);
    }

    private function recordFailure(OutboxEntry $entry, \Throwable $error, int $now): void
    {
        $message = $error->getMessage();
        if ($message === '') {
            $message = $error::class;
        }
        $attempts = $entry->attempts + 1;
        if ($attempts >= $this->maxAttempts) {
            $this->outbox->markDead($entry->id, $message);

            return;
        }
        $this->outbox->markFailed($entry->id, $message, $now + $this->retryDelayMs($attempts) * 1_000_000);
    }
}
