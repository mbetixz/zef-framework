<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\Repository;
use Zef\Framework\Database\SqlQuery;

/**
 * v2.18.0 — Database Core: Repository edge matrix (real SQLite in-memory):
 * CRUD round-trips, criteria null semantics, ordering/pagination, guards.
 *
 * @internal
 */
final class DatabaseRepositoryTest extends TestCase
{
    private PdoConnection $conn;

    private Repository $users;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER, email TEXT)',
        ));
        $this->users = new class($this->conn, 'users') extends Repository {};
    }

    public function testInsertFindUpdateDeleteRoundtrip(): void
    {
        self::assertSame(1, $this->users->insert(['name' => 'Ari', 'age' => 30, 'email' => null]));
        self::assertSame(1, $this->users->insert(['name' => 'Budi', 'age' => 17, 'email' => 'b@x.id']));

        $ari = $this->users->find(1);
        self::assertSame(['id' => 1, 'name' => 'Ari', 'age' => 30, 'email' => null], $ari);

        self::assertNull($this->users->find(999));

        self::assertSame(1, $this->users->update(['age' => 31], ['name' => 'Ari']));
        $after = $this->users->find(1);
        self::assertNotNull($after);
        self::assertSame(31, $after['age']);

        self::assertSame(1, $this->users->delete(['name' => 'Budi']));
        self::assertNull($this->users->find(2));
        self::assertSame(1, $this->users->count());
    }

    public function testFindOneByAndNullCriterionBecomesIsNull(): void
    {
        $this->users->insert(['name' => 'A', 'age' => 1, 'email' => 'a@x.id']);
        $this->users->insert(['name' => 'B', 'age' => 1, 'email' => null]);

        $hit = $this->users->findOneBy(['age' => 1, 'email' => 'a@x.id']);
        self::assertNotNull($hit);
        self::assertSame('A', $hit['name']);
        $nullHit = $this->users->findOneBy(['email' => null]);
        self::assertNotNull($nullHit);
        self::assertSame('B', $nullHit['name']);
        self::assertNull($this->users->findOneBy(['age' => 42]));
    }

    public function testFindByOrderLimitOffset(): void
    {
        foreach ([['C', 3], ['A', 1], ['B', 2], ['D', 4]] as [$n, $a]) {
            $this->users->insert(['name' => $n, 'age' => $a, 'email' => null]);
        }

        $names = array_column($this->users->findBy([], ['age' => 'ASC']), 'name');
        self::assertSame(['A', 'B', 'C', 'D'], $names);

        $desc = array_column($this->users->findBy([], ['age' => 'DESC']), 'name');
        self::assertSame(['D', 'C', 'B', 'A'], $desc);

        $page = array_column($this->users->findBy([], ['age' => 'ASC'], 2, 1), 'name');
        self::assertSame(['B', 'C'], $page);

        self::assertSame(1, $this->users->count(['age' => 1]));
        self::assertSame(4, $this->users->count());
        self::assertFalse($this->users->exists(['name' => 'zzz']));
        self::assertTrue($this->users->exists(['name' => 'A']));
    }

    public function testOffsetWithoutLimitRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offset() requires a limit.');
        $this->users->findBy([], null, null, 5);
    }

    public function testUpdateDeleteWithoutCriteriaRejected(): void
    {
        try {
            $this->users->update(['age' => 1], []);
            self::fail('update without criteria must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('update() requires at least one criterion.', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('delete() requires at least one criterion.');
        $this->users->delete([]);
    }

    public function testInvalidTableNameRejectedAtConstruction(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid table 'users; DROP'.");
        new class($this->conn, 'users; DROP') extends Repository {};
    }

    public function testCountOnEmptyTable(): void
    {
        self::assertSame(0, $this->users->count());
    }
}
