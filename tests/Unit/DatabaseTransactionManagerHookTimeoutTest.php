<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionManager;
use Psr\Log\AbstractLogger;

/**
 * v2.22.1 — issue #65 item 3: cooperative slow-hook guard.
 *
 * When the constructor receives a non-null $hookDurationThresholdMs, each
 * afterCommit hook is timed and a debug-level log entry is emitted via
 * the optional PSR-3 logger when the threshold is exceeded.
 *
 * @internal
 */
final class DatabaseTransactionManagerHookTimeoutTest extends TestCase
{
    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY)'));
    }

    public function testNoThresholdNoLogging(): void
    {
        $logger = new SpyLogger();
        $tx = new TransactionManager($this->conn, null, $logger);

        $tx->withTransaction(function (ConnectionInterface $c): void {
            $c->execute(SqlQuery::raw('INSERT INTO t DEFAULT VALUES'));
            // No-op
        });
        // No afterCommit hooks registered → no log entries.
        self::assertSame([], $logger->records);
    }

    public function testSlowHookLogsDebugEntry(): void
    {
        $logger = new SpyLogger();
        $tx = new TransactionManager($this->conn, 10, $logger); // 10 ms threshold

        $tx->withTransaction(function (ConnectionInterface $c) use ($tx): void {
            $c->execute(SqlQuery::raw('INSERT INTO t DEFAULT VALUES'));
            $tx->afterCommit(static function (): void {
                usleep(50_000); // 50 ms — well above the 10 ms threshold
            });
        });

        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertStringContainsString(
            'slow-hook threshold',
            $logger->records[0]['message'],
        );
        self::assertArrayHasKey('elapsed_ms', $logger->records[0]['context']);
        self::assertArrayHasKey('threshold_ms', $logger->records[0]['context']);
    }

    public function testFastHookUnderThresholdDoesNotLog(): void
    {
        $logger = new SpyLogger();
        $tx = new TransactionManager($this->conn, 1_000, $logger); // 1 s threshold

        $tx->withTransaction(function (ConnectionInterface $c) use ($tx): void {
            $c->execute(SqlQuery::raw('INSERT INTO t DEFAULT VALUES'));
            $tx->afterCommit(static function (): void {
                // No sleep — well under 1 s
            });
        });

        self::assertSame([], $logger->records);
    }

    public function testNullLoggerIsSilentByDefault(): void
    {
        $tx = new TransactionManager($this->conn, 1); // no logger arg → NullLogger

        $tx->withTransaction(function (ConnectionInterface $c) use ($tx): void {
            $c->execute(SqlQuery::raw('INSERT INTO t DEFAULT VALUES'));
            $tx->afterCommit(static function (): void {
                usleep(50_000); // 50 ms — well above 1 ms
            });
        });

        // No assertion needed — NullLogger must not throw.
        $this->expectNotToPerformAssertions();
    }
}

/**
 * Minimal PSR-3 logger that captures records for assertions.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level:string, message:string, context:array}> */
    public array $records = [];

    /**
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : (string) $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
