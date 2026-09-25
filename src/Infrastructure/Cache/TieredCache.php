<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Cache;

/**
 * Two-tier cache (L1 hot + L2 capacity) implementing CacheInterface.
 *
 * Reads check L1 first, then L2, promoting hits into L1; writes go to both
 * tiers (write-through); deletes and clears propagate to both. L1 entries are
 * additionally capped by $l1TtlSeconds so hot-tier staleness stays bounded
 * even when the underlying L1 has a longer native TTL.
 */
final readonly class TieredCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $l1,
        private CacheInterface $l2,
        private ?int $l1TtlSeconds = 60,
    ) {
        if ($this->l1TtlSeconds !== null && $this->l1TtlSeconds < 1) {
            throw new \InvalidArgumentException('L1 TTL must be >= 1 second (or null).');
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->l1->has($key)) {
            return $this->l1->get($key, $default);
        }
        if (!$this->l2->has($key)) {
            return $default;
        }
        $value = $this->l2->get($key, $default);
        $this->l1->set($key, $value, $this->l1TtlSeconds);

        return $value;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->l2->set($key, $value, $ttlSeconds);
        $l1Ttl = $ttlSeconds;
        if ($l1Ttl === null) {
            $l1Ttl = $this->l1TtlSeconds;
        } elseif ($this->l1TtlSeconds !== null) {
            $l1Ttl = min($ttlSeconds, $this->l1TtlSeconds);
        }
        $this->l1->set($key, $value, $l1Ttl);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->l1->delete($key);
        $this->l2->delete($key);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->l1->has($key) || $this->l2->has($key);
    }

    #[\Override]
    public function clear(): void
    {
        $this->l1->clear();
        $this->l2->clear();
    }
}
