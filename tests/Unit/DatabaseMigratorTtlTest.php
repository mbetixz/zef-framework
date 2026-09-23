<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\Migrator;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * v2.18.0 (Guild audit) — Migration lock TTL hardening: per-migration
 * getLockTtl() override, heartbeat renewal before every step, and
 * steal decisions that honour the TTL recorded on the lock row.
 * Deterministic via injected wall-clock on real SQLite in-memory.
 *
 * @internal
 */
final class DatabaseMigratorTtlTest extends TestCase
{
    private const int T0 = 1_700_000_000;

    private PdoConnection $conn;

    private int $now = self::T0;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->now = self::T0;
    }

    // ------------------------------------------------------------- tests

    public function testOverrideTtlIsHeartbeatedOntoTheLockRow(): void
    {
        /** @var list<array<string, mixed>> $captured */
        $captured = [];
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', 900.0, static function (ConnectionInterface $c) use (&$captured): void {
            $captured = $c->fetchAll(
                QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl')->build(),
            );
        }));

        self::assertSame(['20260101000001'], $m->migrate());
        self::assertCount(1, $captured, 'up() must observe the heartbeat-renewed lock row');
        self::assertSame(self::T0, $captured[0]['locked_at']);
        self::assertSame(900.0, $captured[0]['ttl']);
    }

    public function testNullOverrideFallsBackToMigratorDefault(): void
    {
        /** @var list<array<string, mixed>> $captured */
        $captured = [];
        $m = $this->migrator(123.5);
        $m->register($this->migration('20260101000001', null, static function (ConnectionInterface $c) use (&$captured): void {
            $captured = $c->fetchAll(
                QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl')->build(),
            );
        }));

        self::assertSame(['20260101000001'], $m->migrate());
        self::assertSame(123.5, $captured[0]['ttl'], 'null override must renew with the Migrator default TTL');
    }

    public function testNonPositiveOverrideRejected(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', 0.0));

        try {
            $m->migrate();
            self::fail('non-positive TTL override must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(
                "'20260101000001' lock TTL override must be greater than zero",
                $e->getMessage(),
            );
        }
    }

    public function testRowTtlGuardsTheStealDecision(): void
    {
        // A stale-looking holder (age 400s) declared ttl 900s: NOT stale.
        $this->seedLockRow(self::T0 - 400, 900.0);
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001'));

        try {
            $m->migrate();
            self::fail('a lock within its own row TTL must not be stolen');
        } catch (TransactionException $e) {
            self::assertStringContainsString('already held (age 400s, ttl 900s)', $e->getMessage());
        }
        self::assertNotNull($this->lockRow(), 'the declared holder must keep its lock');
    }

    public function testExpiredRowTtlIsStolen(): void
    {
        // Same age (400s) but the row only declared ttl 100s: stale, steal.
        $this->seedLockRow(self::T0 - 400, 100.0);
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001'));

        self::assertSame(['20260101000001'], $m->migrate(), 'an expired row TTL must be stealable');
        self::assertNull($this->lockRow(), 'the stealer releases the lock when done');
    }

    public function testHeartbeatWithHighOverridePreventsMidRunSteal(): void
    {
        // The v2.18.0 audit scenario: a legitimate 400-second step would
        // exceed the default 300s TTL, so the migration declares 900s;
        // a second runner trying to steal mid-step must be refused.
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001', 900.0, function (ConnectionInterface $c): void {
            $this->now = self::T0 + 400; // the step is 400s in — past the default TTL
            $runner = new Migrator($c, fn (): int => $this->now, 300.0);
            $runner->register($this->migration('20260101000002'));

            try {
                $runner->migrate();
                self::fail('a runner must not steal a heartbeat-protected lock mid-step');
            } catch (TransactionException $e) {
                self::assertStringContainsString('already held (age 400s, ttl 900s)', $e->getMessage());
            }
        }));

        self::assertSame(['20260101000001'], $m->migrate());
    }

    public function testHeartbeatUsesOverriddenTtlDuringRollbackToo(): void
    {
        /** @var list<array<string, mixed>> $captured */
        $captured = [];
        $m = $this->migrator();
        $m->register($this->migration(
            '20260101000001',
            600.0,
            null,
            function (ConnectionInterface $c) use (&$captured): void {
                $captured = $c->fetchAll(
                    QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl')->build(),
                );
            },
        ));
        $m->migrate();

        $this->now = self::T0 + 50;
        self::assertSame(['20260101000001'], $m->rollback());
        self::assertCount(1, $captured, 'down() must observe the heartbeat-renewed lock row');
        self::assertSame(self::T0 + 50, $captured[0]['locked_at'], 'rollback must renew locked_at to the current time');
        self::assertSame(600.0, $captured[0]['ttl'], 'rollback must honour the per-migration TTL override');
    }

    // ---------------------------------------------------------- fixtures

    private function migration(
        string $version,
        ?float $ttl = null,
        ?\Closure $up = null,
        ?\Closure $down = null,
    ): MigrationInterface {
        return new readonly class(
            $version,
            $ttl,
            $up ?? static function (ConnectionInterface $c): void {},
            $down ?? static function (ConnectionInterface $c): void {},
        ) implements MigrationInterface {
            public function __construct(
                private string $v,
                private ?float $ttl,
                private \Closure $up,
                private \Closure $down,
            ) {}

            public function version(): string
            {
                return $this->v;
            }

            public function name(): string
            {
                return 'm' . $this->v;
            }

            public function up(ConnectionInterface $connection): void
            {
                ($this->up)($connection);
            }

            public function down(ConnectionInterface $connection): void
            {
                ($this->down)($connection);
            }

            public function getLockTtl(): ?float
            {
                return $this->ttl;
            }
        };
    }

    private function migrator(float $ttl = 300.0): Migrator
    {
        return new Migrator($this->conn, fn (): int => $this->now, $ttl);
    }

    /** @return null|array<string, mixed> */
    private function lockRow(): ?array
    {
        return $this->conn->fetchOne(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl')->where('id', '=', 1)->build(),
        );
    }

    private function seedLockRow(int $lockedAt, float $ttl): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "zef_migrations_lock" ("id" INTEGER NOT NULL PRIMARY KEY, '
            . '"locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));
        $this->conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)->insert(['id' => 1, 'locked_at' => $lockedAt, 'ttl' => $ttl])->build(),
        );
    }
}
