<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

final class InMemoryCache implements CacheInterface
{
    public function __construct(
        private readonly CacheStoreInterface $store,
        private readonly CacheKeyNormalizerInterface $normalizer = new DefaultCacheKeyNormalizer(),
        private readonly CacheClockInterface $clock = new SystemCacheClock(),
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->store->get($this->normalizer->normalize($key));

        return $item instanceof CacheItem ? $item->value : $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            throw new \InvalidArgumentException('Cache TTL must be positive.');
        }
        $expiresAt = $ttlSeconds === null
            ? null
            : $this->clock->nowUnixNano() + ($ttlSeconds * 1_000_000_000);
        $this->store->set($this->normalizer->normalize($key), new CacheItem($value, $expiresAt));
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->store->delete($this->normalizer->normalize($key));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->store->has($this->normalizer->normalize($key));
    }

    #[\Override]
    public function clear(): void
    {
        $this->store->clear();
    }
}
