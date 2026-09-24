<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

use Zef\Framework\Cache\CacheInterface;

/**
 * Caches assembled specification documents behind the v2.9.0 cache port.
 * A missing/mis-shaped cache entry is treated as a miss (never a failure),
 * so a polluted cache cannot break documentation endpoints.
 */
final readonly class SpecificationCache
{
    private const string KEY_PREFIX = 'zef.openapi.spec.';
    private const int DEFAULT_TTL = 3600;

    public function __construct(
        private CacheInterface $cache,
        private int $ttlSeconds = self::DEFAULT_TTL,
    ) {}

    /**
     * @return null|array<string, mixed>
     */
    public function get(string $version): ?array
    {
        $entry = $this->cache->get(self::KEY_PREFIX . $version);
        if (!is_array($entry)) {
            return null;
        }

        // @phpstan-ignore return.type (cache payload shape is the caller's contract)
        return $entry;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function set(string $version, array $spec, ?int $ttlSeconds = null): void
    {
        $this->cache->set(self::KEY_PREFIX . $version, $spec, $ttlSeconds ?? $this->ttlSeconds);
    }

    public function invalidate(string $version): void
    {
        $this->cache->delete(self::KEY_PREFIX . $version);
    }
}
