<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

final class InMemoryCacheStore implements CacheStoreInterface
{
    /**
     * @var array<string,CacheItem>
     */
    private array $items = [];

    public function __construct(
        private readonly int $maxEntries = 10_000,
        private readonly CacheClockInterface $clock = new SystemCacheClock(),
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('Cache capacity must be positive.');
        }
    }

    #[\Override]
    public function get(string $key): ?CacheItem
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        $item = $this->items[$key];
        if ($item->isExpired($this->clock->nowUnixNano())) {
            unset($this->items[$key]);

            return null;
        }

        return $item;
    }

    #[\Override]
    public function set(string $key, CacheItem $item): void
    {
        if (count($this->items) >= $this->maxEntries && !isset($this->items[$key])) {
            $oldestKey = array_key_first($this->items);
            if ($oldestKey !== null) {
                unset($this->items[$oldestKey]);
            }
        }
        $this->items[$key] = $item;
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key) instanceof CacheItem;
    }

    #[\Override]
    public function clear(): void
    {
        $this->items = [];
    }

    public function size(): int
    {
        return count($this->items);
    }
}
