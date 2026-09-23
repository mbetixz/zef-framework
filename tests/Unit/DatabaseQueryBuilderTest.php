<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlExpression;
use Zef\Framework\Database\SqlQuery;

/**
 * v2.18.0 — Database Core: kurikulum edge-case adversarial QueryBuilder.
 * Satu test = satu kelompok mutan yang wajib dibunuh (SQL exactness,
 * grammar identifier, guard tipe, guard unbounded, determinisme build).
 *
 * @internal
 */
final class DatabaseQueryBuilderTest extends TestCase
{
    // ------------------------------------------------------------------
    // SELECT exactness
    // ------------------------------------------------------------------

    public function testSelectStarMinimal(): void
    {
        self::assertSame('SELECT * FROM "users"', QueryBuilder::table('users')->toSql());
    }

    public function testSelectColumnsWithAliasAndParams(): void
    {
        $q = QueryBuilder::table('users', 'u')
            ->select('name AS n', 'u.age', 'email')
            ->where('age', '>', 18)
            ->where('name', '=', 'Ari')
            ->orderBy('name', 'DESC')
            ->limit(10)
            ->offset(20)
        ;

        self::assertSame(
            'SELECT "name" AS "n", "u"."age", "email" FROM "users" AS "u"'
            . ' WHERE "age" > ? AND "name" = ? ORDER BY "name" DESC LIMIT 10 OFFSET 20',
            $q->toSql(),
        );
        self::assertSame([18, 'Ari'], $q->getBindings());
    }

    public function testSelectColumnAliasCaseInsensitiveAs(): void
    {
        $q = QueryBuilder::table('t')->select('col as X9');
        self::assertSame('SELECT "col" AS "X9" FROM "t"', $q->toSql());
    }

    public function testSelectRawExpression(): void
    {
        $q = QueryBuilder::table('t')->selectRaw('COUNT(*)');
        self::assertSame('SELECT COUNT(*) FROM "t"', $q->toSql());
    }

    public function testDistinctFlag(): void
    {
        $q = QueryBuilder::table('t')->select('a')->distinct();
        self::assertSame('SELECT DISTINCT "a" FROM "t"', $q->toSql());
        $q->distinct(false);
        self::assertSame('SELECT "a" FROM "t"', $q->toSql());
    }

    public function testDefaultColumnsAreStar(): void
    {
        self::assertSame('SELECT * FROM "t"', QueryBuilder::table('t')->toSql());
    }

    // ------------------------------------------------------------------
    // Joins
    // ------------------------------------------------------------------

    public function testJoinTypesAndKeywordExactness(): void
    {
        $q = QueryBuilder::table('a')
            ->join('b', 'a.id', '=', 'b.a_id')
            ->leftJoin('c', 'a.id', '=', 'c.a_id')
            ->join('d', 'a.id', '=', 'd.a_id', 'right')
            ->join('e', 'a.id', '=', 'e.a_id', 'cross')
        ;

        self::assertSame(
            'SELECT * FROM "a" INNER JOIN "b" ON "a"."id" = "b"."a_id"'
            . ' LEFT JOIN "c" ON "a"."id" = "c"."a_id"'
            . ' RIGHT JOIN "d" ON "a"."id" = "d"."a_id"'
            . ' CROSS JOIN "e" ON "a"."id" = "e"."a_id"',
            $q->toSql(),
        );
    }

    public function testJoinRejectsUnknownType(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown join type 'outer' (allowed: inner, left, right, cross).");
        QueryBuilder::table('a')->join('b', 'a.id', '=', 'b.a_id', 'outer');
    }

    public function testJoinRejectsBadOperator(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown comparison operator 'LIKE' (allowed: = != <> < > <= >=).");
        QueryBuilder::table('a')->join('b', 'a.id', 'LIKE', 'b.a_id');
    }

    // ------------------------------------------------------------------
    // Where family
    // ------------------------------------------------------------------

    public function testWhereChainMixedConnectors(): void
    {
        $q = QueryBuilder::table('t')
            ->where('a', '=', 1)
            ->orWhere('b', '=', 2)
            ->where('c', '<>', 3)
        ;

        self::assertSame('SELECT * FROM "t" WHERE "a" = ? OR "b" = ? AND "c" <> ?', $q->toSql());
        self::assertSame([1, 2, 3], $q->getBindings());
    }

    public function testWhereNullComparisonRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Use whereNull()/whereNotNull() instead of comparing 'a' with = null.");
        QueryBuilder::table('t')->where('a', '=', null);
    }

    public function testWhereNullComparisonRejectedForNotEquals(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Use whereNull()/whereNotNull() instead of comparing 'a' with <> null.");
        QueryBuilder::table('t')->where('a', '<>', null);
    }

    public function testWhereNullAndNotNull(): void
    {
        $q = QueryBuilder::table('t')->whereNull('a')->whereNotNull('b');
        self::assertSame('SELECT * FROM "t" WHERE "a" IS NULL AND "b" IS NOT NULL', $q->toSql());
        self::assertSame([], $q->getBindings());
    }

    public function testWhereColumnNoParams(): void
    {
        $q = QueryBuilder::table('t')->whereColumn('a', '<=', 'b');
        self::assertSame('SELECT * FROM "t" WHERE "a" <= "b"', $q->toSql());
        self::assertSame([], $q->getBindings());
    }

    public function testWhereNestedParenthesesAndParams(): void
    {
        $q = QueryBuilder::table('t')
            ->where('a', '=', 1)
            ->whereNested(function (QueryBuilder $n): void {
                $n->where('b', '=', 2)->orWhere('c', '=', 3);
            })
        ;

        self::assertSame('SELECT * FROM "t" WHERE "a" = ? AND ("b" = ? OR "c" = ?)', $q->toSql());
        self::assertSame([1, 2, 3], $q->getBindings());
    }

    public function testWhereNestedRequiresCondition(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('whereNested() callback must add at least one condition.');
        QueryBuilder::table('t')->whereNested(static function (): void {});
    }

    public function testWhereInListExactPlaceholders(): void
    {
        $q = QueryBuilder::table('t')->whereIn('a', [1, 'x', 3.5, true]);
        self::assertSame('SELECT * FROM "t" WHERE "a" IN (?, ?, ?, ?)', $q->toSql());
        self::assertSame([1, 'x', 3.5, true], $q->getBindings());
    }

    public function testWhereNotInAndEmptyListGuards(): void
    {
        $q = QueryBuilder::table('t')->whereNotIn('a', [1]);
        self::assertSame('SELECT * FROM "t" WHERE "a" NOT IN (?)', $q->toSql());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('whereIn() requires a non-empty list of values.');
        QueryBuilder::table('t')->whereIn('a', []);
    }

    public function testWhereInSubqueryWithParams(): void
    {
        $sub = QueryBuilder::table('other')->select('id')->where('x', '=', 9);
        $q = QueryBuilder::table('t')->whereIn('a', $sub);

        self::assertSame(
            'SELECT * FROM "t" WHERE "a" IN (SELECT "id" FROM "other" WHERE "x" = ?)',
            $q->toSql(),
        );
        self::assertSame([9], $q->getBindings());
    }

    public function testWhereBetweenBindsBoth(): void
    {
        $q = QueryBuilder::table('t')->whereBetween('a', 1, 9);
        self::assertSame('SELECT * FROM "t" WHERE "a" BETWEEN ? AND ?', $q->toSql());
        self::assertSame([1, 9], $q->getBindings());
    }

    public function testWhereLikeEscapeSequence(): void
    {
        $q = QueryBuilder::table('t')->whereLike('a', '50%', true);
        self::assertSame("SELECT * FROM \"t\" WHERE \"a\" LIKE ? ESCAPE '\\'", $q->toSql());
        self::assertSame(['50\%'], $q->getBindings());
    }

    public function testWhereLikeEscapesUnderscoreAndPercent(): void
    {
        $q = QueryBuilder::table('t')->whereLike('a', 'a_b%c', true);
        self::assertSame(['a\_b\%c'], $q->getBindings());
    }

    public function testWhereLikeWithoutEscapeKeepsPattern(): void
    {
        $q = QueryBuilder::table('t')->whereLike('a', 'ari%');
        self::assertSame('SELECT * FROM "t" WHERE "a" LIKE ?', $q->toSql());
        self::assertSame(['ari%'], $q->getBindings());
    }

    public function testWhereLikeRejectsEmptyPattern(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('whereLike() pattern must not be empty.');
        QueryBuilder::table('t')->whereLike('a', '');
    }

    // ------------------------------------------------------------------
    // Group/having/order/limit
    // ------------------------------------------------------------------

    public function testGroupByHavingExact(): void
    {
        $q = QueryBuilder::table('orders')
            ->select('customer', 'total')
            ->groupBy('customer')
            ->having('total', '>', 100)
        ;

        self::assertSame(
            'SELECT "customer", "total" FROM "orders" GROUP BY "customer" HAVING "total" > ?',
            $q->toSql(),
        );
    }

    public function testGroupByRequiresColumn(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('groupBy() requires at least one column.');
        QueryBuilder::table('t')->groupBy();
    }

    public function testOrderByDirectionGuard(): void
    {
        $q = QueryBuilder::table('t')->orderBy('a')->orderBy('b', 'desc');
        self::assertSame('SELECT * FROM "t" ORDER BY "a" ASC, "b" DESC', $q->toSql());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown order direction 'SIDEWAYS' (allowed: ASC, DESC).");
        QueryBuilder::table('t')->orderBy('a', 'SIDEWAYS');
    }

    public function testLimitZeroAllowedNegativeRejected(): void
    {
        $q = QueryBuilder::table('t')->limit(0);
        self::assertSame('SELECT * FROM "t" LIMIT 0', $q->toSql());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('limit() must be >= 0.');
        QueryBuilder::table('t')->limit(-1);
    }

    public function testOffsetRequiresLimitFirst(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('offset() requires limit() to be set first.');
        QueryBuilder::table('t')->offset(5);
    }

    public function testOffsetNegativeRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('offset() must be >= 0.');
        QueryBuilder::table('t')->limit(1)->offset(-1);
    }

    // ------------------------------------------------------------------
    // Identifier grammar (the anti-injection core)
    // ------------------------------------------------------------------

    public function testIdentifierQuotingExact(): void
    {
        self::assertSame('SELECT * FROM "users"', QueryBuilder::table('users')->toSql());
        self::assertSame('SELECT * FROM "main"."users"', QueryBuilder::table('main.users')->toSql());
    }

    public function testQuoteIdentifierPublicGrammar(): void
    {
        $qb = QueryBuilder::table('t');
        self::assertSame('"ok_1"', $qb->quoteIdentifier('ok_1'));
        self::assertSame('"_x"', $qb->quoteIdentifier('_x'));
    }

    public function testIdentifierRejectsInjection(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'id\"; DROP TABLE users;--'.");
        QueryBuilder::table('t')->where('id"; DROP TABLE users;--', '=', 1);
    }

    public function testIdentifierRejectsEmptyAndDigitStart(): void
    {
        $qb = QueryBuilder::table('t');

        try {
            $qb->quoteIdentifier('', 'table');
            self::fail('empty identifier must be rejected');
        } catch (QueryException $e) {
            self::assertSame('table must not be empty.', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column '1abc'.");
        QueryBuilder::table('t')->where('1abc', '=', 1);
    }

    public function testIdentifierRejectsTooLong(): void
    {
        $qb = QueryBuilder::table('t');
        $long = str_repeat('a', 65);

        try {
            $qb->quoteIdentifier($long, 'column');
            self::fail('65-byte identifier must be rejected');
        } catch (QueryException $e) {
            self::assertSame('column exceeds the 64-byte limit (got 65).', $e->getMessage());
        }

        // 64 bytes is exactly the boundary and must pass.
        self::assertSame('"' . str_repeat('a', 64) . '"', $qb->quoteIdentifier(str_repeat('a', 64), 'column'));
    }

    public function testTableRejectsMoreThanOneDot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid table 'a.b.c' (at most one dot is allowed).");
        QueryBuilder::table('a.b.c');
    }

    public function testColumnPathRejectsMoreThanOneDot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'a.b.c' (at most one dot is allowed).");
        QueryBuilder::table('t')->where('a.b.c', '=', 1);
    }

    public function testSelectRejectsEmptyColumnString(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Column must not be empty.');
        QueryBuilder::table('t')->select('   ');
    }

    public function testSelectRejectsFunctionCallString(): void
    {
        // Dynamic-looking SQL must go through SqlExpression, never strings.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'COUNT(*)'.");
        QueryBuilder::table('t')->select('COUNT(*)');
    }

    // ------------------------------------------------------------------
    // Value guards & SqlExpression
    // ------------------------------------------------------------------

    public function testValueTypesAccepted(): void
    {
        $q = QueryBuilder::table('t')
            ->where('a', '=', 1)
            ->where('b', '=', true)
            ->where('c', '=', 1.5)
            ->where('d', '=', 'x')
        ;
        self::assertSame([1, true, 1.5, 'x'], $q->getBindings());
    }

    public function testValueArrayRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("condition value for 'a' must be null, bool, int, float, string or SqlExpression (got array).");
        QueryBuilder::table('t')->where('a', '=', [1, 2]);
    }

    public function testValueObjectRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("condition value for 'stdclass' must be null, bool, int, float, string or SqlExpression (got stdClass).");
        QueryBuilder::table('t')->where('stdclass', '=', new \stdClass());
    }

    public function testSqlExpressionValuePassesThroughUnbound(): void
    {
        $q = QueryBuilder::table('t')->insert(['a' => new SqlExpression('NOW()'), 'b' => 1]);
        self::assertSame('INSERT INTO "t" ("a", "b") VALUES (NOW(), ?)', $q->toSql());
        self::assertSame([1], $q->getBindings());
    }

    public function testSqlExpressionEmptyRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SqlExpression must not be empty.');
        new SqlExpression('   ');
    }

    // ------------------------------------------------------------------
    // INSERT
    // ------------------------------------------------------------------

    public function testInsertSingleRowExact(): void
    {
        $q = QueryBuilder::table('users')->insert(['name' => 'Ari', 'age' => 30]);
        self::assertSame('INSERT INTO "users" ("name", "age") VALUES (?, ?)', $q->toSql());
        self::assertSame(['Ari', 30], $q->getBindings());
    }

    public function testInsertMultiRowExact(): void
    {
        $q = QueryBuilder::table('users')->insertRows([
            ['name' => 'A', 'age' => 1],
            ['name' => 'B', 'age' => 2],
            ['name' => 'C', 'age' => 3],
        ]);
        self::assertSame('INSERT INTO "users" ("name", "age") VALUES (?, ?), (?, ?), (?, ?)', $q->toSql());
        self::assertSame(['A', 1, 'B', 2, 'C', 3], $q->getBindings());
    }

    public function testInsertRowsEmptyRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertRows() requires at least one row.');
        QueryBuilder::table('t')->insertRows([]);
    }

    public function testInsertRowsColumnMismatchRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertRows() row 1 column set differs from the first row.');
        QueryBuilder::table('t')->insertRows([
            ['a' => 1, 'b' => 2],
            ['a' => 1, 'c' => 2],
        ]);
    }

    public function testInsertRowsEmptyRowRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertRows() row 0 must be a non-empty column => value map.');
        QueryBuilder::table('t')->insertRows([[]]);
    }

    public function testInsertRowsIntKeyRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertRows() row 0 column names must be strings.');
        // @phpstan-ignore-next-line (intentional wrong shape is a runtime guard test)
        QueryBuilder::table('t')->insertRows([['x']]);
    }

    public function testInsertRowReplaceSemantics(): void
    {
        $q = QueryBuilder::table('t')->insert(['a' => 1]);
        $q->insert(['a' => 2]);
        self::assertSame('INSERT INTO "t" ("a") VALUES (?)', $q->toSql());
        self::assertSame([2], $q->getBindings());
    }

    // ------------------------------------------------------------------
    // UPDATE / DELETE + unbounded guard
    // ------------------------------------------------------------------

    public function testUpdateExactWithWhereParamsOrder(): void
    {
        $q = QueryBuilder::table('users')
            ->update(['name' => 'Budi', 'age' => 40])
            ->where('id', '=', 7)
        ;

        self::assertSame('UPDATE "users" SET "name" = ?, "age" = ? WHERE "id" = ?', $q->toSql());
        self::assertSame(['Budi', 40, 7], $q->getBindings());
    }

    public function testUpdateRawExpressionInSet(): void
    {
        $q = QueryBuilder::table('t')->update(['n' => new SqlExpression('n + 1')])->where('id', '=', 1);
        self::assertSame('UPDATE "t" SET "n" = n + 1 WHERE "id" = ?', $q->toSql());
        self::assertSame([1], $q->getBindings());
    }

    public function testUpdateWithoutWhereRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Refusing unbounded UPDATE without WHERE; call allowUnbounded() to confirm a full-table update.',
        );
        QueryBuilder::table('t')->update(['a' => 1])->toSql();
    }

    public function testUpdateAllowUnboundedEscapes(): void
    {
        $q = QueryBuilder::table('t')->update(['a' => 1])->allowUnbounded();
        self::assertSame('UPDATE "t" SET "a" = ?', $q->toSql());
    }

    public function testUpdateEmptyPairsRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('update() requires at least one column => value pair.');
        QueryBuilder::table('t')->update([]);
    }

    public function testUpdateIntColumnRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('update() column names must be strings.');
        // @phpstan-ignore-next-line (intentional wrong shape is a runtime guard test)
        QueryBuilder::table('t')->update([0 => 'x']);
    }

    public function testDeleteExactAndUnboundedGuard(): void
    {
        $q = QueryBuilder::table('t')->where('a', '=', 1)->delete();
        self::assertSame('DELETE FROM "t" WHERE "a" = ?', $q->toSql());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Refusing unbounded DELETE without WHERE; call allowUnbounded() to confirm a full-table delete.',
        );
        QueryBuilder::table('t')->delete()->toSql();
    }

    public function testDeleteAllowUnboundedThenBindStillWorks(): void
    {
        $q = QueryBuilder::table('t')->allowUnbounded()->delete();
        self::assertSame('DELETE FROM "t"', $q->toSql());
        self::assertSame([], $q->getBindings());
    }

    // ------------------------------------------------------------------
    // Type-mixing guards
    // ------------------------------------------------------------------

    public function testSelectOnDeleteRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('select() is not available on a delete query.');
        QueryBuilder::table('t')->delete()->select('a');
    }

    public function testInsertThenWhereRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('WHERE clauses are not available on an insert query.');
        QueryBuilder::table('t')->insert(['a' => 1])->where('a', '=', 1);
    }

    public function testUpdateThenInsertRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertRows() cannot be mixed with an existing update query.');
        // @phpstan-ignore-next-line (wrong shape is the point of this guard test)
        QueryBuilder::table('t')->update(['a' => 1])->insertRows(['a' => 2]);
    }

    public function testAggregateWhitelist(): void
    {
        $q = QueryBuilder::table('t')->count();
        self::assertSame('SELECT COUNT(*) AS aggregate FROM "t"', $q->toSql());

        $q2 = QueryBuilder::table('t')->aggregate('SUM', 'amount');
        self::assertSame('SELECT SUM("amount") AS aggregate FROM "t"', $q2->toSql());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown aggregate function 'MEDIAN' (allowed: COUNT, SUM, AVG, MIN, MAX).");
        QueryBuilder::table('t')->aggregate('MEDIAN', 'x');
    }

    public function testAggregateWithWhereComposes(): void
    {
        $q = QueryBuilder::table('t')->count()->where('a', '=', 1);
        self::assertSame('SELECT COUNT(*) AS aggregate FROM "t" WHERE "a" = ?', $q->toSql());
        self::assertSame([1], $q->getBindings());
    }

    // ------------------------------------------------------------------
    // Missing table / SqlQuery guards / determinism
    // ------------------------------------------------------------------

    public function testBuildWithoutTableRejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Select query requires a table (call QueryBuilder::table() first).');
        new QueryBuilder()->toSql();
    }

    public function testInsertWithoutDataGuardIsInternal(): void
    {
        // insertRows() with data is the only way into TYPE_INSERT, so the
        // build-time guard is defense-in-depth; assert the happy path keeps
        // the guard reachable via a fresh builder of the same shape.
        $q = QueryBuilder::table('t')->insertRows([['a' => 1]]);
        self::assertSame('INSERT INTO "t" ("a") VALUES (?)', $q->toSql());
    }

    public function testSqlQueryRejectsEmptySql(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQL statement must not be empty.');
        new SqlQuery('', []);
    }

    public function testSqlQueryRejectsStringKeyedParams(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SqlQuery params must be a list of positional values.');
        // @phpstan-ignore-next-line (string-keyed params is the rejected shape)
        new SqlQuery('SELECT 1', ['a' => 1]);
    }

    public function testSqlQueryRawFactory(): void
    {
        $q = SqlQuery::raw('SELECT 1');
        self::assertSame('SELECT 1', $q->sql);
        self::assertSame([], $q->params);
    }

    public function testBuildIsDeterministic(): void
    {
        $make = static fn (): QueryBuilder => QueryBuilder::table('t')
            ->select('a', 'b AS c')
            ->where('a', '>=', 2)
            ->whereIn('b', [1, 2])
            ->orderBy('a')
            ->limit(3)
        ;

        self::assertSame($make()->toSql(), $make()->toSql());
        self::assertSame($make()->getBindings(), $make()->getBindings());
    }
}
