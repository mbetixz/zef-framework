<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * In-memory {@see CheckpointStoreInterface} keyed by projection id.
 */
final class InMemoryCheckpointStore implements CheckpointStoreInterface
{
    /** @var array<string, int> */
    private array $checkpoints = [];

    #[\Override]
    public function get(string $projectionId): ?int
    {
        return $this->checkpoints[$projectionId] ?? null;
    }

    #[\Override]
    public function set(string $projectionId, int $globalSequence): void
    {
        EventGrammar::assertAggregateType($projectionId, 'projection id');
        EventGrammar::assertGlobalSequence($globalSequence);
        $this->checkpoints[$projectionId] = $globalSequence;
    }

    /**
     * Test/ops helper: number of tracked projections.
     */
    public function count(): int
    {
        return \count($this->checkpoints);
    }
}
