<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

interface CacheStoreInterface
{
    public function get(string $key): ?CacheItem;

    public function set(string $key, CacheItem $item): void;

    public function delete(string $key): void;

    public function has(string $key): bool;

    public function clear(): void;
}
