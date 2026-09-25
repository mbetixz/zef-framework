<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 — Application layer (in-process orchestration)
 * Cluster-safe job idempotency over a LockStoreInterface (issue #68):
 * exactly-once execution per key inside a TTL window across nodes.
 */

namespace Zef\Framework\Job;

use Zef\Framework\Cache\LockStoreInterface;

/**
 * Lock-lease based JobIdempotencyStoreInterface adapter.
 *
 * Where InMemoryJobIdempotencyStore caches the producer's RESULT in-process,
 * this adapter provides the cross-node guarantee the queue worker needs:
 * within the TTL window, the producer for a given key runs at most once
 * cluster-wide.
 *
 * Semantics:
 *
 * - remember() first acquires a lease `zef:jobidem:<key>` for the whole
 *   TTL window. Winning the lease means this node is responsible for the
 *   key: the producer runs and its result is returned.
 * - Losing the lease (another node already claimed/ran it inside the
 *   window) returns null and the producer is NOT executed — the caller
 *   observes "handled elsewhere" and can report a no-op success.
 * - After a successful run the lease is deliberately kept until the TTL
 *   lapses: duplicate deliveries inside the window short-circuit to null.
 * - When the producer throws, the lease is released best-effort and the
 *   exception propagates, so the worker's retry policy can legitimately
 *   re-run the job instead of every retry collapsing to null.
 *
 * The lease TTL doubles as the idempotency window (default 3600s). Lock
 * TTLs are bounded 1..86400s by the port, so windows above one day are
 * rejected up front.
 */
final readonly class LockingJobIdempotencyStore implements JobIdempotencyStoreInterface
{
    private const string KEY_PREFIX = 'zef:jobidem:';

    private const int MAX_KEY_LENGTH = 191;

    public function __construct(private LockStoreInterface $store) {}

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException('Invalid job idempotency key.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Job idempotency window must be 1..86400 seconds.');
        }

        // A fresh owner token per call: re-acquiring must NOT be treated as
        // a lease refresh by the store (the port refreshes for a repeated
        // owner), so even a same-process repeat within the window must lose.
        $owner = 'jobidem-' . bin2hex(random_bytes(8));
        $leaseKey = self::KEY_PREFIX . $key;
        if (!$this->store->acquire($leaseKey, $owner, $ttlSeconds)) {
            return null;
        }

        try {
            return $producer();
        } catch (\Throwable $e) {
            // Release the lease so retries may run; a lapsed lease simply
            // returns false, which is harmless here.
            try {
                $this->store->release($leaseKey, $owner);
            } catch (\Throwable) {
                // Never mask the producer's failure with a store error.
            }

            throw $e;
        }
    }
}
