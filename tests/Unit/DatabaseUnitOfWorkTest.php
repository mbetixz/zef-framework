<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\UnitOfWork;

/**
 * v2.22.0 — UoW-lite: deferred-write queue with transactional flush
 * semantics (real SQLite integration + order-capturing fakes).
 *
 * @internal
 */
final class DatabaseUnitOfWorkTest extends TestCase
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

    public function testFlushExecutesRecordedQueriesFifo(): void
    {
        $uow = new UnitOfWork();
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second']));
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['third']));

        self::assertSame(3, $uow->pending());
        self::assertSame(3, $uow->flush($this->conn));
        self::assertSame(['first', 'second', 'third'], $this->names(), 'operations must run FIFO');
        self::assertSame(0, $uow->pending(), 'queue must be empty after a successful flush');
    }

    public function testFlushRunsArbitraryOperationsWithConnection(): void
    {
        $uow = new UnitOfWork();
        $seen = [];
        $uow->record(function (ConnectionInterface $c) use (&$seen): void {
            $seen[] = $c::class;
        });
        $uow->record(static function (ConnectionInterface $c): void {
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['from-closure']));
        });

        $uow->flush($this->conn);

        self::assertSame([PdoConnection::class], $seen, 'operations receive the flush connection');
        self::assertSame(['from-closure'], $this->names());
    }

    public function testFlushWithEmptyQueueReturnsZero(): void
    {
        $uow = new UnitOfWork();
        self::assertSame(0, $uow->pending());
        self::assertSame(0, $uow->flush($this->conn));
        self::assertSame([], $this->names());
    }

    public function testFlushFailureLeavesQueueEmptyAndPropagates(): void
    {
        $uow = new UnitOfWork();
        $ranSecond = false;
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['ok']));
        $uow->record(static function (ConnectionInterface $c): void {
            $c->execute(SqlQuery::raw('INSERT INTO t (missing_column) VALUES (1)'));
        });
        $uow->record(static function () use (&$ranSecond): void {
            $ranSecond = true;
        });

        self::assertSame(3, $uow->pending());

        try {
            $uow->flush($this->conn);
            self::fail('Expected the query failure to propagate.');
        } catch (QueryException) {
        }

        self::assertSame(0, $uow->pending(), 'queue is cleared BEFORE execution — no partial replay');
        self::assertFalse($ranSecond, 'execution stops at the first failure');
        self::assertSame(['ok'], $this->names(), 'autocommit mode keeps already-applied operations');
    }

    public function testFlushInsideTransactionIsAtomic(): void
    {
        $uow = new UnitOfWork();
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['kept']));
        $this->conn->beginTransaction();
        $uow->flush($this->conn);
        self::assertSame(['kept'], $this->names(), 'visible inside the open transaction');
        $this->conn->rollBack();
        self::assertSame([], $this->names(), 'transaction rollback reverts flushed writes');
    }

    public function testReentrantFlushIsRefused(): void
    {
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use ($uow): void {
            $uow->flush($c);
        });

        try {
            $uow->flush($this->conn);
            self::fail('Expected the re-entrant flush to be refused.');
        } catch (TransactionException $e) {
            self::assertSame('UnitOfWork::flush() re-entered while flushing.', $e->getMessage());
        }
    }

    public function testRecordingDuringFlushIsRefused(): void
    {
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use ($uow): void {
            $uow->record(static function (ConnectionInterface $inner): void {
                $inner->execute(SqlQuery::raw('SELECT 1'));
            });
        });

        try {
            $uow->flush($this->conn);
            self::fail('Expected mid-flush recording to be refused.');
        } catch (TransactionException $e) {
            self::assertSame('Cannot record operations while the UnitOfWork is flushing.', $e->getMessage());
        }
    }

    public function testDiscardDropsPendingOperations(): void
    {
        $uow = new UnitOfWork();
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['one']));
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['two']));

        self::assertSame(2, $uow->discard());
        self::assertSame(0, $uow->pending());
        self::assertSame(0, $uow->flush($this->conn), 'flush after discard executes nothing');
        self::assertSame([], $this->names());
    }

    public function testQueueAcceptsNewOperationsAfterFailedFlush(): void
    {
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c): void {
            $c->execute(SqlQuery::raw('INSERT INTO t (missing_column) VALUES (1)'));
        });
        try {
            $uow->flush($this->conn);
            self::fail('Expected the query failure to propagate.');
        } catch (QueryException) {
        }

        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['fresh']));
        self::assertSame(1, $uow->pending(), 'queue must accept operations after a failed flush');
        self::assertSame(1, $uow->flush($this->conn));
        self::assertSame(['fresh'], $this->names());
    }

    public function testQueueAcceptsNewOperationsAfterSuccessfulFlush(): void
    {
        $uow = new UnitOfWork();
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        self::assertSame(1, $uow->flush($this->conn));

        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second']));
        self::assertSame(1, $uow->pending(), 'queue must accept operations after a successful flush');
        self::assertSame(1, $uow->flush($this->conn));
        self::assertSame(['first', 'second'], $this->names());
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = [];
        foreach ($this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t ORDER BY id')) as $row) {
            self::assertIsString($row['name']);
            $names[] = $row['name'];
        }

        return $names;
    }
}
