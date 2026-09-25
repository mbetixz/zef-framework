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
 * - When the producer throws, the exception propagates (the worker's
 *   retry policy must see it), and the lease is released best-effort so
 *   retries may legitimately re-run the job. When that release itself
 *   fails (store outage), the failure is reported via error_log() instead
 *   of being swallowed silently: the lease then stays held for the rest
 *   of the window — degraded but observable, and self-healing once the
 *   TTL lapses. The producer's exception is never masked by a store
 *   error, and the store error is never masked either.
 *
 * The lease TTL doubles as the idempotency window (default 3600s). Lock
 * TTLs are bounded 1..86400s by the port, so windows above one day are
 * rejected up front.
 */
final readonly class LockingJobIdempotencyStore implements JobIdempotencyStoreInterface
{
    private const string KEY_PREFIX = 'zef:jobidem:';

    /**
     * Matches InMemoryJobIdempotencyStore's 191-byte key bound (itself the
     * classic MySQL utf8mb4-friendly index limit) so both adapters of the
     * port accept exactly the same key space.
     */
    private const int MAX_KEY_LENGTH = 256;

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
            // returns false, which is harmless here. A release that itself
            // throws (store outage) must stay observable: the lease would
            // remain held until the TTL lapses, blocking retries for this
            // key — so report it rather than swallowing it silently. The
            // producer's failure still propagates untouched.
            try {
                $this->store->release($leaseKey, $owner);
            } catch (\Throwable) {
                // Never mask the producer's failure with a store error.
            }
                $this->store->release($leaseKey, $owner);
            } catch (\Throwable $releaseFailure) {
                error_log(sprintf(
                    '[ZEF][job] idempotency lease release failed for key "%s" (window %ds): %s — lease remains held until TTL lapse; retries for this key are blocked until then.',
                    $key,
                    $ttlSeconds,
                    $releaseFailure->getMessage(),
                ));
            }

            throw $e;
        }
    }
}
