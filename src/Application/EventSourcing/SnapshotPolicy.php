<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Decides when {@see AggregateRepository::persist()} should also write an
 * aggregate snapshot: every time the stream version crosses a multiple of
 * the configured interval.
 *
 * Version 0 (empty aggregate) never snapshots.
 */
final class SnapshotPolicy
{
    public const int DEFAULT_INTERVAL = 100;

    private function __construct(
        private readonly int $interval,
    ) {}

    public static function every(int $interval): self
    {
        if ($interval < 1) {
            throw new EventSourcingException("Snapshot interval must be >= 1 (got {$interval}).");
        }

        return new self($interval);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_INTERVAL);
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function shouldSnapshot(int $version): bool
    {
        return $version >= 1 && $version % $this->interval === 0;
    }
}
