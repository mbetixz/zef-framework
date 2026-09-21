<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

interface IdempotencyStoreInterface
{
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed;
}

/*
 * Shared idempotency logic for CQRS and Job stores.
 * Max key length is parameterised (CQRS: 128, Job: 191).
 */
