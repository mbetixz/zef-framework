<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
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
                throw $this->makePdoException('deadlock', '40P01');
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
        $uow->record(static function (ConnectionInterface $c): never {
            throw new \LogicException('not retryable');
        });

        $policy = new UnitOfWorkRetryPolicy();
        $this->expectException(\LogicException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingExhaustsAttemptsAndPropagatesLast(): void
    {
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c): never {
            throw $this->makePdoException('deadlock', '40P01');
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
                throw $this->makePdoException('deadlock', '40P01');
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 5, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed);
        self::assertSame(3, $attempt, 'tried 3 times before success');
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    #[DataProvider('transactionDepths')]
    public function testRetryRollsBackAllAttemptWrites(int $depth): void
    {
        for ($level = 0; $level < $depth; ++$level) {
            $this->conn->beginTransaction();
        }
        $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('before-flush')"));

        $calls = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'first';
            $c->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('first')"));
        });
        $uow->record(function (ConnectionInterface $c) use (&$calls, &$attempt): void {
            $calls[] = 'second';
            $c->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('second')"));
            if (++$attempt === 1) {
                throw $this->makePdoException('lock wait timeout', '1205');
            }
        });

        self::assertSame(2, $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy(initialDelayMs: 0)));
        self::assertSame(['first', 'second', 'first', 'second'], $calls);
        self::assertSame(0, $uow->pending());
        self::assertSame($depth, $this->conn->transactionLevel());
        self::assertSame(
            [['name' => 'before-flush'], ['name' => 'first'], ['name' => 'second']],
            $this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t ORDER BY id')),
        );
        for ($level = 0; $level < $depth; ++$level) {
            $this->conn->rollBack();
        }
        if ($depth > 0) {
            self::assertSame([], $this->conn->fetchAll(SqlQuery::raw('SELECT * FROM t')));
        }
    }

    /** @return iterable<string, array{int}> */
    public static function transactionDepths(): iterable
    {
        yield 'standalone transaction' => [0];

        yield 'outer transaction' => [1];

        yield 'nested transaction' => [2];
    }

    #[DataProvider('terminalFailures')]
    public function testTerminalFailureRollsBackWritesAndRetainsQueue(bool $retryable): void
    {
        $this->conn->beginTransaction();
        $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('before-flush')"));
        $failure = $retryable ? $this->makePdoException('lock wait timeout', '1205') : new \LogicException('stop');
        $attempts = 0;
        $uow = new UnitOfWork();
        $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('first')"));
        $uow->record(static function (ConnectionInterface $c) use ($failure, &$attempts): never {
            ++$attempts;

            throw $failure;
        });

        try {
            $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0));
            self::fail('Expected the operation failure.');
        } catch (\Throwable $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame($retryable ? 2 : 1, $attempts);
        self::assertSame(2, $uow->pending());
        self::assertSame(1, $this->conn->transactionLevel());
        self::assertSame([['name' => 'before-flush']], $this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t')));
        $this->conn->rollBack();
        self::assertSame(2, $uow->discard());
        $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('recovered')"));
        self::assertSame(1, $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy()));
    }

    /** @return iterable<string, array{bool}> */
    public static function terminalFailures(): iterable
    {
        yield 'non-retryable' => [false];

        yield 'exhausted' => [true];
    }

    public function testRollbackFailureStopsRetriesEvenWhenPolicyWouldRetryIt(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $rollbackFailure = $this->makePdoException('transaction was aborted', '1205');
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack')->willThrowException($rollbackFailure);
        $connection->expects(self::never())->method('commit');
        $attempts = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$attempts): never {
            ++$attempts;

            throw $this->makePdoException('lock wait timeout', '1205');
        });

        try {
            $uow->flushRetrying($connection, new UnitOfWorkRetryPolicy(initialDelayMs: 0));
            self::fail('Expected the rollback failure.');
        } catch (\Throwable $e) {
            self::assertSame($rollbackFailure, $e);
        }
        self::assertSame(1, $attempts);
        self::assertSame(1, $uow->pending());
    }

    public function testLostTransactionDoesNotReplayFlushInANewTransaction(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $connection = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
        $connection->beginTransaction();
        $attempts = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use ($pdo, &$attempts): void {
            if (++$attempts === 1) {
                // Simulate a driver aborting the entire transaction,
                // including the flush savepoint, without adapter bookkeeping.
                $pdo->rollBack();

                throw $this->makePdoException('deadlock', '40P01');
            }
        });

        try {
            $uow->flushRetrying($connection, new UnitOfWorkRetryPolicy(initialDelayMs: 0));
            self::fail('Expected rollback to the lost savepoint to fail.');
        } catch (QueryException $e) {
            self::assertStringContainsString('savepoint', $e->getMessage());
        }
        self::assertSame(1, $attempts);
        self::assertSame(1, $uow->pending());
        self::assertFalse($pdo->inTransaction());
    }

    public function testCommitFailureDoesNotReplayWritesOrClearQueue(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $commitFailure = $this->makePdoException('unknown commit outcome', '1205');
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit')->willThrowException($commitFailure);
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::once())->method('execute')->willReturn(1);
        $uow = new UnitOfWork();
        $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('first')"));

        try {
            $uow->flushRetrying($connection, new UnitOfWorkRetryPolicy(initialDelayMs: 0));
            self::fail('Expected the commit failure.');
        } catch (\Throwable $e) {
            self::assertSame($commitFailure, $e);
        }
        self::assertSame(1, $uow->pending());
    }

    /**
     * Build a testable PDOException with a SQLSTATE string. Real
     * PDOExceptions are populated by PHP internals at runtime; the public
     * constructor only accepts an int $code, so we extend the class and
     * populate errorInfo directly.
     */
    private function makePdoException(string $message, string $sqlState): \PDOException
    {
        return new class($message, $sqlState) extends \PDOException {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message, 0);
                $this->errorInfo = [$sqlState, 0, $message];
            }
        };
    }
}
