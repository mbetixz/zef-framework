<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 (issue #68) — leader election semantics over the
 * LockStoreInterface port. Uses the in-memory store with an injectable
 * clock so leadership acquisition, renewal, resignation and TTL lapse are
 * all deterministic (no sleeps); store-agnostic by design, the Redis
 * adapter is covered by RedisLockStoreTest.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\Cache\LeaderElector;

/**
 * @internal
 */
final class LeaderElectorTest extends TestCase
{
    /** @var array{0:int} virtual unix-second clock */
    private array $now = [1_700_000_000];

    private InMemoryLockStore $store;

    protected function setUp(): void
    {
        $this->now = [1_700_000_000];
        $this->store = new InMemoryLockStore($this->advanceClock(...));
    }

    public function testFirstContenderBecomesLeader(): void
    {
        $elector = new LeaderElector($this->store, 'scheduler', 'node-a', 15);

        self::assertTrue($elector->acquireLeadership());
        self::assertTrue($elector->isLeader());
        self::assertSame('node-a', $elector->holder());
        self::assertSame('node-a', $elector->identity());
        self::assertSame(15, $elector->ttlSeconds());
    }

    public function testSecondReplicaLosesTheRace(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);

        self::assertTrue($a->acquireLeadership());
        self::assertFalse($b->acquireLeadership());
        self::assertFalse($b->isLeader());
        self::assertTrue($a->isLeader());
        self::assertSame('node-a', $b->holder());
    }

    public function testRenewalExtendsLeadership(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        self::assertTrue($a->acquireLeadership());

        // 14s later (inside the TTL): renewal succeeds and pushes the
        // horizon 15s beyond that point.
        $this->now[0] += 14;
        self::assertTrue($a->renewLeadership());

        // 14s after the renewal the original lease would have lapsed at
        // +15s, but the renewed one still holds: node-b still loses.
        $this->now[0] += 14;
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertFalse($b->acquireLeadership());
        self::assertTrue($a->isLeader());
    }

    public function testRenewalFailsAfterTtlLapseAndLeadershipChanges(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        self::assertTrue($a->acquireLeadership());

        // Renewal never happened: one second past the TTL the lease is
        // free, the old leader observes the loss, and another replica can
        // take over — crash recovery without operator action.
        $this->now[0] += 16;
        self::assertFalse($a->renewLeadership());
        self::assertFalse($a->isLeader());

        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertTrue($b->acquireLeadership());
        self::assertSame('node-b', $b->holder());
    }

    public function testRenewalByNonLeaderReturnsFalse(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertTrue($a->acquireLeadership());

        self::assertFalse($b->renewLeadership());
        self::assertTrue($a->isLeader());
    }

    public function testResignFreesTheLeaseForTheNextReplica(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertTrue($a->acquireLeadership());

        self::assertTrue($a->resign());
        self::assertFalse($a->isLeader());
        self::assertNull($a->holder());

        self::assertTrue($b->acquireLeadership());
        self::assertSame('node-b', $a->holder());
    }

    public function testResignByNonLeaderReturnsFalse(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertTrue($a->acquireLeadership());

        self::assertFalse($b->resign());
        self::assertTrue($a->isLeader());
    }

    public function testElectionsAreIsolatedByName(): void
    {
        $a1 = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $a2 = new LeaderElector($this->store, 'outbox-relay', 'node-a', 15);
        $b2 = new LeaderElector($this->store, 'outbox-relay', 'node-b', 15);

        self::assertTrue($a1->acquireLeadership());
        self::assertTrue($b2->acquireLeadership());
        self::assertFalse($a2->acquireLeadership());
        self::assertSame('node-b', $a2->holder());
        self::assertSame('node-a', $a1->holder());
    }

    public function testDefaultIdentityIsStablePerInstanceAndUniqueAcrossInstances(): void
    {
        $one = new LeaderElector($this->store, 'scheduler');
        $two = new LeaderElector($this->store, 'scheduler');

        self::assertMatchesRegularExpression('/^node-.+\-\d+\-[0-9a-f]{8}$/', $one->identity());
        self::assertSame($one->identity(), $one->identity());
        self::assertNotSame($one->identity(), $two->identity());
    }

    /**
     * @dataProvider invalidConfigurationProvider
     */
    public function testConfigurationIsValidated(string $name, ?string $identity, int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LeaderElector($this->store, $name, $identity, $ttl);
    }

    /**
     * @return array<string, array{0:string, 1:null|string, 2:int}>
     */
    public static function invalidConfigurationProvider(): array
    {
        return [
            'empty name' => ['', 'node-a', 15],
            'oversized name' => [str_repeat('n', 129), 'node-a', 15],
            'zero ttl' => ['scheduler', 'node-a', 0],
            'negative ttl' => ['scheduler', 'node-a', -5],
            'ttl above port bound' => ['scheduler', 'node-a', 86401],
            'empty identity' => ['scheduler', '', 15],
            'oversized identity' => ['scheduler', str_repeat('i', 257), 15],
        ];
    }

    public function testLapsedLeaderCannotResignTheNewLeadersLease(): void
    {
        $a = new LeaderElector($this->store, 'scheduler', 'node-a', 15);
        $b = new LeaderElector($this->store, 'scheduler', 'node-b', 15);
        self::assertTrue($a->acquireLeadership());

        $this->now[0] += 16;
        self::assertTrue($b->acquireLeadership());

        // The old leader's resign() must NOT release node-b's lease.
        self::assertFalse($a->resign());
        self::assertTrue($b->isLeader());
    }

    private function advanceClock(): int
    {
        return $this->now[0];
    }
}
