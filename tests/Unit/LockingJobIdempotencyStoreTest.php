<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 (issue #68) — cluster-safe job idempotency: a
 * lock-lease gives exactly-once execution per key inside the TTL window
 * across nodes, failures release the lease so retries can re-run, and
 * the window lapses automatically. Deterministic via the in-memory
 * store's injectable clock (no sleeps).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\Cache\LockStoreInterface;
use Zef\Framework\Job\LockingJobIdempotencyStore;

/**
 * @internal
 */
final class LockingJobIdempotencyStoreTest extends TestCase
{
    private const int T0 = 1_700_000_000;

    /** @var array{0:int} virtual unix-second clock */
    private array $now = [self::T0];

    private InMemoryLockStore $store;

    protected function setUp(): void
    {
        $this->now = [self::T0];
        $this->store = new InMemoryLockStore($this->clock(...));
    }

    public function testFirstCallerRunsTheProducerAndGetsItsResult(): void
    {
        $idempotency = new LockingJobIdempotencyStore($this->store);

        $result = $idempotency->remember('job|abc', static fn (): string => 'built-report');

        self::assertSame('built-report', $result);
    }

    public function testSecondNodeWithinWindowSkipsExecution(): void
    {
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;

            return 'built-report';
        };
        $nodeA = new LockingJobIdempotencyStore($this->store);
        $nodeB = new LockingJobIdempotencyStore($this->store);

        self::assertSame('built-report', $nodeA->remember('job|abc', $producer));
        self::assertNull($nodeB->remember('job|abc', $producer));
        self::assertSame(1, $runs, 'The producer must run exactly once cluster-wide.');
    }

    public function testSameNodeRetryingWithinWindowAlsoSkips(): void
    {
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;

            return 'built-report';
        };
        $nodeA = new LockingJobIdempotencyStore($this->store);

        self::assertSame('built-report', $nodeA->remember('job|abc', $producer));
        self::assertNull($nodeA->remember('job|abc', $producer));
        self::assertSame(1, $runs);
    }

    public function testProducerFailureReleasesTheLeaseForRetries(): void
    {
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;
            if ($runs === 1) {
                throw new \RuntimeException('boom');
            }

            return 'rebuilt-report';
        };
        $nodeA = new LockingJobIdempotencyStore($this->store);

        try {
            $nodeA->remember('job|abc', $producer);
            self::fail('The producer failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // The retry may run again — the lease was released on failure.
        self::assertSame('rebuilt-report', $nodeA->remember('job|abc', $producer));
        self::assertSame(2, $runs);
    }

    public function testWindowLapseAllowsARun(): void
    {
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;

            return 'built-report';
        };
        $nodeA = new LockingJobIdempotencyStore($this->store);
        self::assertSame('built-report', $nodeA->remember('job|abc', $producer, 60));

        // Inside the window: skipped.
        $this->now[0] += 59;
        self::assertNull($nodeA->remember('job|abc', $producer, 60));

        // Past the window: a new execution is allowed.
        $this->now[0] += 2;
        self::assertSame('built-report', $nodeA->remember('job|abc', $producer, 60));
        self::assertSame(2, $runs);
    }

    public function testKeysAreIsolated(): void
    {
        $nodeA = new LockingJobIdempotencyStore($this->store);

        self::assertSame('one', $nodeA->remember('job|a', static fn (): string => 'one'));
        self::assertSame('two', $nodeA->remember('job|b', static fn (): string => 'two'));
        self::assertNull($nodeA->remember('job|a', static fn (): string => 'one-again'));
    }

    public function testProducerFailureFromAnotherNodeReleasesForEveryone(): void
    {
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;

            throw new \RuntimeException('boom');
        };
        $nodeA = new LockingJobIdempotencyStore($this->store);
        $nodeB = new LockingJobIdempotencyStore($this->store);

        try {
            $nodeA->remember('job|abc', $producer);
            self::fail('The producer failure must propagate.');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $runs);

        // nodeB gets a fair shot because nodeA released the lease.
        try {
            $nodeB->remember('job|abc', $producer);
            self::fail('The producer failure must propagate.');
        } catch (\RuntimeException) {
        }
        self::assertSame(2, $runs);
    }

    /**
     * @dataProvider invalidInputProvider
     */
    public function testInputIsValidated(string $key, int $ttlSeconds): void
    {
        $idempotency = new LockingJobIdempotencyStore($this->store);
        $this->expectException(\InvalidArgumentException::class);
        $idempotency->remember($key, static fn (): string => 'never', $ttlSeconds);
    }

    /**
     * @return array<string, array{0:string, 1:int}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty key' => ['', 3600],
            'oversized key' => [str_repeat('k', 192), 3600],
            'zero window' => ['job|abc', 0],
            'negative window' => ['job|abc', -1],
            'window above lock bound' => ['job|abc', 86401],
        ];
    }

    public function testComposesWithTheInProcessWorkerClaim(): void
    {
        // The exact call shape InProcessJobWorker::execute() uses.
        $idempotency = new LockingJobIdempotencyStore($this->store);
        $claimKey = hash('sha256', 'reports.build|job-42');
        $runs = 0;
        $producer = static function () use (&$runs): string {
            ++$runs;

            return 'report-42';
        };

        $first = $idempotency->remember($claimKey, $producer);
        $duplicate = $idempotency->remember($claimKey, $producer);

        self::assertSame('report-42', $first);
        self::assertNull($duplicate);
        self::assertSame(1, $runs);
    }

    public function testReleaseFailureIsReportedAndProducerFailureStillPropagates(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zef-idem-err-');
        self::assertNotFalse($logFile);
        $previousLog = ini_set('error_log', $logFile);

        try {
            $store = new class implements LockStoreInterface {
                #[\Override]
                public function acquire(string $key, string $owner, int $ttlSeconds): bool
                {
                    return true;
                }

                #[\Override]
                public function release(string $key, string $owner): bool
                {
                    throw new \RuntimeException('store outage');
                }

                #[\Override]
                public function refresh(string $key, string $owner, int $ttlSeconds): bool
                {
                    return false;
                }

                #[\Override]
                public function holder(string $key): ?string
                {
                    return null;
                }
            };
            $idempotency = new LockingJobIdempotencyStore($store);

            try {
                $idempotency->remember('job|abc', static fn (): never => throw new \RuntimeException('producer-boom'), 60);
                self::fail('The producer failure must propagate.');
            } catch (\RuntimeException $e) {
                self::assertSame('producer-boom', $e->getMessage());
            }

            $logged = (string) file_get_contents($logFile);
            self::assertStringContainsString('lease release failed', $logged);
            self::assertStringContainsString('job|abc', $logged);
            self::assertStringContainsString('store outage', $logged);
        } finally {
            ini_set('error_log', (string) $previousLog);
            @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        }
    }

    private function clock(): int
    {
        return $this->now[0];
    }
}
