<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * @internal
 */
final class DatabaseTransactionFailureTest extends TestCase
{
    public function testDeferredConstraintCommitFailureRollsBackAndAllowsReuse(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child (parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static function (ConnectionInterface $c): void {
                $c->execute(SqlQuery::raw('INSERT INTO child VALUES (1)'));
            });
            self::fail('The deferred constraint must reject the commit.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Failed to commit transaction:', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));

        $result = $conn->transaction(static function (ConnectionInterface $c): string {
            $c->execute(SqlQuery::raw('INSERT INTO parent VALUES (1)'));
            $c->transaction(static function (ConnectionInterface $inner): void {
                $inner->execute(SqlQuery::raw('INSERT INTO child VALUES (1)'));
            });

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['parent_id' => 1]], $conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));
    }

    #[DataProvider('cleanupFailures')]
    public function testCommitFailurePreservedAndUncertainConnectionRejected(string $failure): void
    {
        $primary = new \PDOException('commit failed');
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willThrowException($primary);
        if ($failure === 'state check throws') {
            $pdo->method('inTransaction')->willThrowException(new \PDOException('connection lost'));
        } else {
            $pdo->method('inTransaction')->willReturn($failure !== 'transaction already ended');
        }
        if ($failure === 'rollback throws') {
            $pdo->expects(self::once())->method('rollBack')->willThrowException(new \PDOException('rollback failed'));
        } elseif ($failure === 'rollback returns false') {
            $pdo->expects(self::once())->method('rollBack')->willReturn(false);
        } else {
            $pdo->expects(self::never())->method('rollBack');
        }
        $pdo->expects(self::never())->method('prepare');
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static fn (): int => 42);
            self::fail('Commit failure must propagate.');
        } catch (TransactionException $e) {
            self::assertSame($primary, $e->getPrevious());
        }

        self::assertSame(0, $conn->transactionLevel());

        try {
            $conn->beginTransaction();
            self::fail('An uncertain connection must not begin another transaction.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('unusable', $e->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $conn->execute(SqlQuery::raw('SELECT 1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cleanupFailures(): iterable
    {
        yield 'rollback throws' => ['rollback throws'];

        yield 'rollback returns false' => ['rollback returns false'];

        yield 'transaction already ended' => ['transaction already ended'];

        yield 'state check throws' => ['state check throws'];
    }

    public function testCallbackErrorPreservedWhenRollbackFails(): void
    {
        $primary = new \Error('callback failed');
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack')->willThrowException(new \PDOException('rollback failed'));
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static fn (): never => throw $primary);
        } catch (\Error $e) {
            self::assertSame($primary, $e);
        }

        self::assertSame(0, $conn->transactionLevel());
        $this->expectException(ConnectionException::class);
        $conn->lastInsertId();
    }

    public function testFalseCommitResultTriggersRollback(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(false);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static fn (): int => 42);
            self::fail('A false commit result must not report success.');
        } catch (TransactionException $e) {
            self::assertSame('Failed to commit transaction: driver returned false.', $e->getMessage());
        }

        self::assertSame(0, $conn->transactionLevel());
        $conn->beginTransaction();
        self::assertSame(1, $conn->transactionLevel());
    }

    public function testFailedSavepointReleaseRollsBackOnlyInnerScope(): void
    {
        $pdo = new class('sqlite::memory:') extends \PDO {
            public function exec(string $statement): false|int
            {
                if ($statement === 'RELEASE SAVEPOINT zef_sp2') {
                    throw new \PDOException('release failed');
                }

                return parent::exec($statement);
            }
        };
        $conn = $this->wrap($pdo);
        $conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER)'));
        $conn->transaction(static function (ConnectionInterface $c) use ($pdo): void {
            $c->execute(SqlQuery::raw('INSERT INTO t VALUES (1)'));

            try {
                $c->transaction(static function (ConnectionInterface $inner): void {
                    $inner->execute(SqlQuery::raw('INSERT INTO t VALUES (2)'));
                });
                self::fail('Savepoint release must fail.');
            } catch (QueryException $e) {
                self::assertStringContainsString('release failed', $e->getMessage());
            }
            self::assertSame(1, $c->transactionLevel());
            self::assertTrue($pdo->inTransaction());
        });

        self::assertSame(0, $conn->transactionLevel());
        self::assertSame([['id' => 1]], $conn->fetchAll(SqlQuery::raw('SELECT * FROM t')));
    }

    private function wrap(\PDO $pdo): PdoConnection
    {
        return new PdoConnection(
            ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']),
            $pdo,
        );
    }
}
