<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Cache;

/**
 * Mutual-exclusion port for critical sections and stampede prevention.
 *
 * Locks are leased (TTL-bounded) and owned: only the owner that acquired a
 * lock may release or refresh it. Implementations must be safe to poll —
 * `acquire()` returns false instead of blocking, so callers can retry with
 * their own backoff.
 */
interface LockStoreInterface
{
    /**
     * Try to acquire a leased lock. Returns false when somebody else holds it.
     *
     * @param string $key lock identity, 1..256 bytes
     * @param string $owner opaque owner token (caller-generated, e.g. session/worker ID)
     * @param int $ttlSeconds lease duration, 1..86400
     */
    public function acquire(string $key, string $owner, int $ttlSeconds): bool;

    /**
     * Release a lock; succeeds only for the current owner.
     */
    public function release(string $key, string $owner): bool;

    /**
     * Extend the lease of a lock still owned by $owner.
     */
    public function refresh(string $key, string $owner, int $ttlSeconds): bool;

    /**
     * Owner token of the current holder, or null when free/expired.
     */
    public function holder(string $key): ?string;
}
