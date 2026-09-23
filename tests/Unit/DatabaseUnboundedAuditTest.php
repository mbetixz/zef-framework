<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MeterInterface;

/**
 * v2.18.0 (Guild audit) — allowUnbounded() auditability + QueryBuilder
 * identifier DX: unbounded UPDATE/DELETE executions emit a telemetry
 * counter and a WARN audit log carrying the full SQL (both ports are
 * optional), SqlQuery::$unbounded marks only WHERE-less confirmed
 * writes, and raw-SQL-as-identifier mistakes point straight at the
 * SqlExpression escape hatch.
 *
 * @internal
 */
final class DatabaseUnboundedAuditTest extends TestCase
{
    // ------------------------------------------------------------ fakes

    private AuditRecordingMeter $meter;

    private AuditRecordingLogExporter $logs;

    private PdoConnection $conn;

    protected function setUp(): void
    {
        $this->meter = new AuditRecordingMeter();
        $this->logs = new AuditRecordingLogExporter();
        $this->conn = new PdoConnection(
            ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']),
            null,
            $this->meter,
            $this->logs,
        );
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
        $this->conn->execute(QueryBuilder::table('t')->insert(['id' => 1, 'name' => 'a'])->build());
        $this->conn->execute(QueryBuilder::table('t')->insert(['id' => 2, 'name' => 'b'])->build());
    }

    // --------------------------------------------- SqlQuery flag (pure)

    public function testUnboundedFlagMarksOnlyWherelessConfirmedWrites(): void
    {
        self::assertTrue(
            QueryBuilder::table('t')->update(['name' => 'x'])->allowUnbounded()->build()->unbounded,
            'a confirmed WHERE-less UPDATE is unbounded',
        );
        self::assertTrue(
            QueryBuilder::table('t')->delete()->allowUnbounded()->build()->unbounded,
            'a confirmed WHERE-less DELETE is unbounded',
        );
        self::assertFalse(
            QueryBuilder::table('t')->update(['name' => 'x'])->allowUnbounded()->where('id', '=', 1)->build()->unbounded,
            'allowUnbounded() WITH a WHERE is still a bounded query',
        );
        self::assertFalse(
            QueryBuilder::table('t')->delete()->allowUnbounded()->where('id', '=', 1)->build()->unbounded,
            'allowUnbounded() WITH a WHERE is still a bounded query',
        );
        self::assertFalse(
            QueryBuilder::table('t')->update(['name' => 'x'])->where('id', '=', 1)->build()->unbounded,
            'bounded writes default the flag to false',
        );
        self::assertFalse(
            QueryBuilder::table('t')->select('id')->build()->unbounded,
            'reads are never flagged',
        );
        self::assertFalse(
            SqlQuery::raw('CREATE TABLE x (id INTEGER)')->unbounded,
            'raw DDL is never flagged',
        );
    }

    // ------------------------------------------------- execution audit

    public function testUnboundedUpdateEmitsCounterAndAuditLog(): void
    {
        $affected = $this->conn->execute(
            QueryBuilder::table('t')->update(['name' => 'x'])->allowUnbounded()->build(),
        );

        self::assertSame(2, $affected);
        self::assertCount(1, $this->meter->increments, 'exactly one telemetry counter per unbounded execution');
        self::assertSame('zef.db.unbounded_statement', $this->meter->increments[0][0]);
        self::assertSame(['op' => 'update'], $this->meter->increments[0][1]);

        self::assertCount(1, $this->logs->records);
        $record = $this->logs->records[0];
        self::assertSame('WARN', $record->severity);
        self::assertStringContainsString('Unbounded update executed (allowUnbounded)', $record->body);
        self::assertStringContainsString('2 row(s) affected', $record->body);
        self::assertSame('update', $record->attributes['op']);
        self::assertSame(2, $record->attributes['rows']);
        self::assertIsString($record->attributes['sql']);
        self::assertStringStartsWith('UPDATE', $record->attributes['sql']);
    }

    public function testUnboundedDeleteEmitsCounterAndAuditLog(): void
    {
        $affected = $this->conn->execute(
            QueryBuilder::table('t')->delete()->allowUnbounded()->build(),
        );

        self::assertSame(2, $affected);
        self::assertSame('zef.db.unbounded_statement', $this->meter->increments[0][0]);
        self::assertSame(['op' => 'delete'], $this->meter->increments[0][1]);
        self::assertSame('delete', $this->logs->records[0]->attributes['op']);
    }

    public function testBoundedWritesEmitNoAuditTraffic(): void
    {
        $affected = $this->conn->execute(
            QueryBuilder::table('t')->update(['name' => 'x'])->where('id', '=', 1)->build(),
        );

        self::assertSame(1, $affected);
        self::assertSame([], $this->meter->increments, 'bounded writes must stay silent');
        self::assertSame([], $this->logs->records, 'bounded writes must stay silent');
    }

    public function testNullPortsRemainValid(): void
    {
        $conn = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']));
        $conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER)'));
        $affected = $conn->execute(
            QueryBuilder::table('t')->delete()->allowUnbounded()->build(),
        );

        self::assertSame(0, $affected, 'no observability ports wired — execution must simply work');
    }

    // ------------------------------------- identifier DX (SqlExpression)

    public function testRawFragmentInSelectPointsToSqlExpression(): void
    {
        try {
            QueryBuilder::table('t')->select('COUNT(*)');
            self::fail('raw SQL fragment must be rejected as a column');
        } catch (QueryException $e) {
            self::assertStringContainsString("Invalid column 'COUNT(*)'", $e->getMessage());
            self::assertStringContainsString('SqlExpression', $e->getMessage());
            self::assertStringContainsString('selectRaw', $e->getMessage());
        }
    }

    public function testRawFragmentInUpdateSetPointsToSqlExpression(): void
    {
        try {
            QueryBuilder::table('t')->update(['COALESCE(name, ?)' => 1]);
            self::fail('raw SQL fragment must be rejected as an update column');
        } catch (QueryException $e) {
            self::assertStringContainsString('new SqlExpression(...)', $e->getMessage());
        }
    }

    public function testRawFragmentAsTablePointsToSqlExpression(): void
    {
        try {
            QueryBuilder::table('users u');
            self::fail('space-separated raw table fragment must be rejected');
        } catch (QueryException $e) {
            self::assertStringContainsString("Invalid table 'users u'", $e->getMessage());
            self::assertStringContainsString('SqlExpression', $e->getMessage());
        }
    }

    public function testPlainTypoStaysConcise(): void
    {
        try {
            QueryBuilder::table('t')->select('9abc');
            self::fail('identifier starting with a digit must be rejected');
        } catch (QueryException $e) {
            self::assertSame("Invalid column '9abc'.", $e->getMessage(), 'plain typos keep the short message');
        }
    }
}

/**
 * Recording fake for the MeterInterface port.
 *
 * @internal
 */
final class AuditRecordingMeter implements MeterInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $increments = [];

    public function increment(string $name, float|int $value = 1, array $attributes = []): void
    {
        $this->increments[] = [$name, $attributes];
    }

    public function observe(string $name, float $value, array $attributes = []): void {}

    public function snapshot(): array
    {
        return [];
    }
}

/**
 * Recording fake for the LogExporterInterface port.
 *
 * @internal
 */
final class AuditRecordingLogExporter implements LogExporterInterface
{
    /** @var list<LogRecord> */
    public array $records = [];

    public function exportLogs(array $records): void
    {
        foreach ($records as $record) {
            $this->records[] = $record;
        }
    }

    public function shutdown(): void {}
}
