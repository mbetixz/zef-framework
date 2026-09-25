<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 — Application layer (in-process orchestration)
 * Leader election over any LockStoreInterface (issue #68): replicas race
 * for a named lease, the winner acts as leader for as long as it keeps
 * renewing within the TTL, and a crashed holder loses leadership
 * automatically once the lease lapses.
 */

namespace Zef\Framework\Cache;

/**
 * Named leadership lease on top of a LockStoreInterface.
 *
 * Any number of replicas can share the same election name; the underlying
 * lock store guarantees exactly one of them holds the lease at a time.
 * Leadership is *time-bounded*: the leader must call renewLeadership()
 * within the TTL window (rule of thumb: renew at least every TTL / 2) or
 * the lease lapses and another replica wins it — no operator action is
 * needed to recover from a crashed leader.
 *
 * The owner token defaults to a per-instance identity
 * (`node-<hostname>-<pid>-<rand>`) so two processes on the same host still
 * count as different contenders; inject an explicit identity for
 * deterministic tests or when a stable node ID (e.g. from config) exists.
 *
 * The lock store is the single source of truth: isLeader() and holder()
 * consult it on every call rather than trusting local state, so an expired
 * or stolen lease is observed immediately.
 */
final readonly class LeaderElector
{
    private const string KEY_PREFIX = 'zef:leader:';

    private string $identity;

    private string $lockKey;

    public function __construct(
        private LockStoreInterface $store,
        string $name,
        ?string $identity = null,
        private int $ttlSeconds = 15,
    ) {
        $this->assertName($name);
        $this->assertTtl($ttlSeconds);
        $this->lockKey = self::KEY_PREFIX . $name;
        $this->identity = $identity ?? $this->defaultIdentity();
        $this->assertIdentity($this->identity);
    }

    /**
     * Race for leadership. True when this instance now holds the lease
     * (freshly acquired or still held from a previous call).
     */
    public function acquireLeadership(): bool
    {
        return $this->store->acquire($this->lockKey, $this->identity, $this->ttlSeconds);
    }

    /**
     * Extend the lease. False (and leadership lost) once the TTL has
     * lapsed or another replica took over — the caller must then stop
     * acting as leader until acquireLeadership() succeeds again.
     */
    public function renewLeadership(): bool
    {
        return $this->store->refresh($this->lockKey, $this->identity, $this->ttlSeconds);
    }

    /**
     * Voluntarily give up leadership (rolling restarts, graceful
     * shutdown). Returns false when this instance was not the leader.
     */
    public function resign(): bool
    {
        return $this->store->release($this->lockKey, $this->identity);
    }

    /**
     * Whether this instance is the current leader, per the lock store.
     *
     * Advisory only, and subject to TOCTOU: between this check and the
     * caller's next action the lease can expire or be taken over (for
     * example when this instance stops renewing within the TTL). Treat
     * "isLeader() == true" as "allowed to act now", not a guarantee that
     * nobody else will act concurrently; leadership lapses are bounded by
     * the TTL, and consumers that need strict single-execution should
     * guard the action itself with a lock-store lease (see
     * LockingJobIdempotencyStore) instead of relying on this check.
     */
    public function isLeader(): bool
    {
        return $this->store->holder($this->lockKey) === $this->identity;
    }

    /**
     * Owner token of the current leader (any replica), or null when the
     * lease is free/lapsed.
     */
    public function holder(): ?string
    {
        return $this->store->holder($this->lockKey);
    }

    /**
     * This instance's contender identity.
     */
    public function identity(): string
    {
        return $this->identity;
    }

    /**
     * Lease TTL in seconds, as configured for this elector.
     */
    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /** @return string node-<hostname>-<pid>-<rand> contender token */
    private function defaultIdentity(): string
    {
        $hostname = gethostname();
        $hostPart = ($hostname === false || $hostname === '') ? 'unknown-host' : $hostname;
        $pid = getmypid();

        return sprintf(
            'node-%s-%d-%s',
            $hostPart,
            $pid === false ? 0 : $pid,
            bin2hex(random_bytes(4)),
        );
    }

    private function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException('Leader election name must be 1..128 bytes.');
        }
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Leader lease TTL must be 1..86400 seconds.');
        }
    }

    private function assertIdentity(string $identity): void
    {
        if ($identity === '' || strlen($identity) > 256) {
            throw new \InvalidArgumentException('Leader identity must be 1..256 bytes.');
        }
    }
}
