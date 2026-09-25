<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use Zef\Framework\EventSourcing\AggregateRoot;
use Zef\Framework\EventSourcing\RowCast;

/**
 * Test fixture aggregate for the v2.23.0 upcasting tests: a value-only
 * state machine that only understands the CURRENT event shape (e.v2) —
 * a legacy event reaching apply() proves the upcaster did not run.
 * Lives in its own file so PSR-4 autoloading works in isolated runs.
 *
 * @internal
 */
final class UpcastTestAggregate extends AggregateRoot
{
    /** @var list<string> */
    private array $seen = [];

    private function __construct(
        private readonly string $id,
        private int $value = 0,
    ) {}

    public static function start(string $id, int $initial): self
    {
        $aggregate = new self($id);
        $aggregate->recordEvent('e.v2', ['value' => $initial]);

        return $aggregate;
    }

    public function value(): int
    {
        return $this->value;
    }

    /** @return list<string> */
    public function seen(): array
    {
        return $this->seen;
    }

    #[\Override]
    public function aggregateId(): string
    {
        return $this->id;
    }

    #[\Override]
    public static function aggregateType(): string
    {
        return 'test.upcast';
    }

    #[\Override]
    public function snapshotState(): array
    {
        return ['id' => $this->id, 'value' => $this->value];
    }

    #[\Override]
    public static function restoreFromSnapshot(array $state, int $version): static
    {
        $aggregate = new self(RowCast::string($state['id'] ?? null), RowCast::int($state['value'] ?? null));
        $aggregate->seedVersion($version);

        return $aggregate;
    }

    #[\Override]
    public static function createEmpty(string $aggregateId): static
    {
        return new self($aggregateId);
    }

    #[\Override]
    protected function apply(string $eventType, array $payload): void
    {
        match ($eventType) {
            'e.v2' => $this->value += RowCast::int($payload['value'] ?? null),
            default => throw new \LogicException("Unknown event {$eventType}"),
        };
        $this->seen[] = $eventType;
    }
}
