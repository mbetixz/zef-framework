<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use Zef\Framework\EventSourcing\AggregateRoot;
use Zef\Framework\EventSourcing\RowCast;

/**
 * Test fixture aggregate: balance-only state with an event log.
 * Lives in its own file so PSR-4 autoloading works in isolated runs.
 *
 * @internal
 */
final class EventSourcingTestAccount extends AggregateRoot
{
    /** @var list<string> */
    private array $log = [];

    private function __construct(
        private readonly string $id,
        private int $balance = 0,
    ) {}

    /**
     * @param array<mixed> $metadata
     */
    public static function open(string $id, int $initial, array $metadata = []): self
    {
        $aggregate = new self($id);
        $aggregate->recordEvent('account.opened', ['initial' => $initial], $metadata);

        return $aggregate;
    }

    /**
     * @param array<mixed> $metadata
     */
    public function deposit(int|string $amount, array $metadata = []): void
    {
        $this->recordEvent('account.deposited', ['amount' => $amount], $metadata);
    }

    public function depositPublicly(string $eventType): void
    {
        $this->recordEvent($eventType);
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public static function aggregateType(): string
    {
        return 'test.account';
    }

    public function balance(): int
    {
        return $this->balance;
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    #[\Override]
    public function snapshotState(): array
    {
        return ['id' => $this->id, 'balance' => $this->balance, 'log' => $this->log];
    }

    #[\Override]
    public static function restoreFromSnapshot(array $state, int $version): static
    {
        $aggregate = new self(RowCast::string($state['id'] ?? null), RowCast::int($state['balance'] ?? null));
        // @phpstan-ignore-next-line intentionally flexible fixture restore
        $aggregate->log = (array) ($state['log'] ?? []);
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
            'account.opened' => $this->balance = RowCast::int($payload['initial'] ?? null),
            'account.deposited' => $this->balance += RowCast::int($payload['amount'] ?? null),
            default => throw new \LogicException("Unknown event {$eventType}"),
        };
        $this->log[] = $eventType;
    }
}
