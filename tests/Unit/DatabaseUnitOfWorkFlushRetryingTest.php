<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * v2.22.1 — issue #65 item 2: UoW flushRetrying() preserves the queue
 * across retry attempts and re-runs the operations until either success
 * or policy exhaustion.
 *
 * Uses real SQLite in-memory + closures that throw transient failures on
 * the first N attempts (deterministic, no fake connection needed).
 *
 * @internal
 */
final class DatabaseUnitOfWorkFlushRetryingTest extends TestCase
{
    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    public function testFlushRetryingSucceedsOnFirstAttempt(): void
    {
        $calls = [];
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'op-1';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        });
        $uow->record(function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'op-2';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second']));
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(2, $executed);
        self::assertSame(['op-1', 'op-2'], $calls);
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    public function testFlushRetryingReplaysOperationsOnTransientFailure(): void
    {
        $calls = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$calls, &$attempt): void {
            $calls[] = 'op-1';
            ++$attempt;
            if ($attempt < 2) {
                throw new \PDOException('deadlock', '40P01');
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed, 'final attempt succeeded');
        self::assertSame(['op-1', 'op-1'], $calls, 'operation replayed on retry');
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    public function testFlushRetryingPropagatesNonRetryableThrowable(): void
    {
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c): void {
            throw new \LogicException('not retryable');
        });

        $policy = new UnitOfWorkRetryPolicy();
        $this->expectException(\LogicException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingExhaustsAttemptsAndPropagatesLast(): void
    {
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c): void {
            throw new \PDOException('deadlock', '40P01');
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0);
        $this->expectException(\PDOException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingRefusesReentry(): void
    {
        $uow = new UnitOfWork();
        $ref = new \ReflectionProperty($uow, 'flushing');
        $ref->setValue($uow, true);

        $policy = new UnitOfWorkRetryPolicy();
        $this->expectException(TransactionException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingEmptyQueueReturnsZero(): void
    {
        $uow = new UnitOfWork();
        $policy = new UnitOfWorkRetryPolicy();
        self::assertSame(0, $uow->flushRetrying($this->conn, $policy));
    }

    public function testFlushRetryingPreservesQueueAcrossRetries(): void
    {
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$attempt): void {
            ++$attempt;
            if ($attempt < 3) {
                throw new \PDOException('deadlock', '40P01');
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 5, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed);
        self::assertSame(3, $attempt, 'tried 3 times before success');
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }
}
