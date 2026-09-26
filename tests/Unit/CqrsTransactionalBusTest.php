<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\IdempotencyStoreInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\TransactionalCommandBus;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\TransactionManager;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventSubscriberInterface;

/**
 * v2.22.0 — TransactionalCommandBus: command handling inside a managed
 * transaction, UoW flush before commit, post-commit event fan-out.
 * Full integration on real SQLite in-memory.
 *
 * @internal
 */
final class CqrsTransactionalBusTest extends TestCase
{
    private ConnectionInterface $conn;

    private TransactionManager $tx;

    /** @var list<object> */
    private array $firedEvents;

    private EventBusInterface $eventBus;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
        $this->tx = new TransactionManager($this->conn);
        $this->firedEvents = [];
        $this->eventBus = $this->recordingBus($this->firedEvents);
    }

    public function testFailedFlushDoesNotCacheResultOrEmitEvents(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(
            new CommandBus(new InMemoryIdempotencyStore(), eventBus: $this->eventBus),
            $this->tx,
            $uow,
        );
        $calls = 0;
        $bus->register(\stdClass::class, function () use ($uow, &$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('direct')"));
            $uow->recordQuery(SqlQuery::raw($calls === 1
                ? 'INSERT INTO missing_table VALUES (1)'
                : "INSERT INTO t (name) VALUES ('deferred')"));

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'flush-retry');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected flush failure.');
        } catch (QueryException) {
        }
        self::assertSame([], $this->names());
        self::assertSame([], $this->firedEvents);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(['direct', 'deferred'], $this->names());
        self::assertCount(1, $this->firedEvents);
    }

    public function testFailedCommitDoesNotCacheResult(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $failCommit = true;
        $connection->method('transaction')->willReturnCallback(function (callable $fn) use (&$failCommit): mixed {
            // Inject a failure after the complete handler/flush callback,
            // with the connection rolling back the real SQLite writes.
            return $this->conn->transaction(static function (ConnectionInterface $conn) use ($fn, &$failCommit): mixed {
                $result = $fn($conn);
                if ($failCommit) {
                    $failCommit = false;

                    throw new \RuntimeException('commit failed');
                }

                return $result;
            });
        });
        $tx = new TransactionManager($connection);
        $bus = new TransactionalCommandBus(
            new CommandBus(new InMemoryIdempotencyStore(), eventBus: $this->eventBus, transactions: $tx),
            $tx,
        );
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));

            return new CqrsEventResult(null, [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'commit-retry');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected commit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('commit failed', $e->getMessage());
        }
        self::assertSame([], $this->names());
        self::assertSame([], $this->firedEvents);
        self::assertNull($bus->dispatch(new \stdClass(), $context));
        self::assertNull($bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(['committed'], $this->names());
        self::assertCount(1, $this->firedEvents);
    }

    public function testRealCommitFailureRollsBackAndSameKeyCanRetry(): void
    {
        $this->conn->execute(SqlQuery::raw('PRAGMA foreign_keys = ON'));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE child (parent_id INTEGER REFERENCES t(id) DEFERRABLE INITIALLY DEFERRED)'));
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): string {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (id, name) VALUES (1, 'committed')"));
            $this->conn->execute(new SqlQuery('INSERT INTO child (parent_id) VALUES (?)', [$calls === 1 ? 999 : 1]));

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'real-commit-failure');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected deferred constraint to fail at commit.');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Failed to commit transaction', $e->getMessage());
        }
        self::assertSame(0, $this->conn->transactionLevel());
        self::assertSame(0, $this->tx->level());
        self::assertSame([], $this->names());
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(['committed'], $this->names());
        self::assertSame([['parent_id' => 1]], $this->conn->fetchAll(SqlQuery::raw('SELECT parent_id FROM child')));
    }

    public function testAfterCommitHookFailureStillCachesCommittedResult(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore(), eventBus: $this->eventBus), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));
            $this->tx->afterCommit(static function (): never {
                throw new \RuntimeException('hook failed');
            });

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'hook-failure');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected hook failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('hook failed', $e->getMessage());
        }
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
        self::assertSame([], $this->firedEvents, 'hook failure stops later event fan-out');
        self::assertSame(['committed'], $this->names());
    }

    public function testListenerFailureStillCachesCommittedResult(): void
    {
        $calls = 0;
        $listenerCalls = 0;
        $bus = new TransactionalCommandBus(new CommandBus(
            new InMemoryIdempotencyStore(),
            eventBus: $this->recordingBus($this->firedEvents, function () use (&$listenerCalls): never {
                ++$listenerCalls;
                self::assertSame(0, $this->conn->transactionLevel());
                self::assertSame(['committed'], $this->names());

                throw new \RuntimeException('listener failed');
            }),
        ), $this->tx);
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'listener-failure');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected listener failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
        self::assertSame(1, $listenerCalls);
    }

    public function testNestedIdempotentMissIsRejectedBeforeHandlerRuns(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, static function () use (&$calls): string {
            ++$calls;

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'nested-command');

        try {
            $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context));
            self::fail('Cannot publish an uncommitted result from a nested scope.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('outermost', $e->getMessage());
        }
        self::assertSame(0, $calls);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context)));
        self::assertSame(1, $calls);
    }

    public function testReentrantDispatchDuringCommitHooksDoesNotRepeatHandler(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $context = CqrsContext::create(idempotencyKey: 'reentrant-hook');
        $calls = 0;
        $bus->register(\stdClass::class, function () use ($bus, $context, &$calls): string {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));
            $this->tx->afterCommit(static function () use ($bus, $context): void {
                $bus->dispatch(new \stdClass(), $context);
            });

            return 'ok';
        });

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('An in-flight key must not execute again.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('in progress', $e->getMessage());
        }
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
        self::assertSame(['committed'], $this->names());
    }

    public function testPlainBusRejectsCacheMissInsideManagedTransaction(): void
    {
        $bus = new CommandBus(new InMemoryIdempotencyStore(), transactions: $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, static function () use (&$calls): string {
            ++$calls;

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'plain-managed');

        try {
            $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context));
            self::fail('Cannot cache an uncommitted result.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('outermost', $e->getMessage());
        }
        self::assertSame(0, $calls);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
    }

    public function testStoreProducerIncludesCommitAndReplaySkipsFlush(): void
    {
        $store = $this->createMock(IdempotencyStoreInterface::class);
        $memory = new InMemoryIdempotencyStore();
        $producerCalls = 0;
        $key = 'producer-scope';
        $store->expects(self::exactly(2))->method('remember')
            ->with(hash('sha256', \stdClass::class . '|' . $key), self::isType('callable'), 123)
            ->willReturnCallback(function (string $key, callable $producer, int $ttl) use ($memory, &$producerCalls): mixed {
                return $memory->remember($key, function () use ($producer, &$producerCalls): mixed {
                    ++$producerCalls;
                    $result = $producer();
                    self::assertSame(0, $this->conn->transactionLevel(), 'publication must follow commit');
                    self::assertSame(['committed'], $this->names());

                    return $result;
                }, $ttl);
            })
        ;
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus($store, 123), $this->tx, $uow);
        $bus->register(\stdClass::class, static function () use ($uow): string {
            $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: $key);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('unrelated')"));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $producerCalls);
        self::assertSame(1, $uow->pending(), 'replay must not flush unrelated work');
        self::assertSame(['committed'], $this->names());
    }

    public function testCustomBusStillRunsInsideTransactionAndFlushes(): void
    {
        $inner = $this->createMock(CommandBusInterface::class);
        $uow = new UnitOfWork();
        $inner->expects(self::once())->method('dispatch')->willReturnCallback(function () use ($uow): string {
            self::assertSame(1, $this->conn->transactionLevel());
            $uow->recordQuery(SqlQuery::raw("INSERT INTO t (name) VALUES ('custom')"));

            return 'ok';
        });
        $bus = new TransactionalCommandBus($inner, $this->tx, $uow);

        self::assertSame('ok', $bus->dispatch(new \stdClass()));
        self::assertSame(['custom'], $this->names());
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testHandlerWritesAndUowFlushCommitAtomically(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, $uow);
        $conn = $this->conn;
        $bus->register(\stdClass::class, new readonly class($conn, $uow) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['uow-write']));
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['direct-write']));

                return 'ok';
            }
        });

        self::assertSame('ok', $bus->dispatch(new \stdClass()));
        // The handler records FIRST and writes directly SECOND, yet the
        // direct write lands first: UoW records are deferred until the
        // post-handler flush. This asserts the deferral contract.
        self::assertSame(['direct-write', 'uow-write'], $this->names(), 'UoW writes execute at flush, not at record time');
        self::assertSame(0, $uow->pending(), 'flush cleared the queue');
    }

    public function testHandlerFailureRollsBackWritesAndDiscardsEvents(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, $uow);
        $conn = $this->conn;
        $bus->register(\stdClass::class, new readonly class($conn, $uow) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): never
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['uow-write']));
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['direct-write']));

                throw new \RuntimeException('handler explodes');
            }
        });

        try {
            $bus->dispatch(new \stdClass());
            self::fail('Expected handler failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->names(), 'nothing survives the rollback');
        self::assertSame([], $this->firedEvents, 'a rolled-back command emits no events');
    }

    public function testEventsFireOnlyAfterOutermostCommit(): void
    {
        // The listener records the connection's transaction level at
        // fan-out time: 0 proves the commit already happened (the same
        // connection sees its own uncommitted writes, so visibility alone
        // would not prove ordering).
        $observedLevel = null;
        $conn = $this->conn;
        $bus = new TransactionalCommandBus(
            new CommandBus(
                eventBus: $this->recordingBus($this->firedEvents, static function () use (&$observedLevel, $conn): void {
                    $observedLevel = $conn->transactionLevel();
                }),
                transactions: $this->tx,
            ),
            $this->tx,
        );
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): mixed => new CqrsEventResult('done', [new \stdClass()]));

        self::assertSame('done', $bus->dispatch(new \stdClass()));
        self::assertSame(0, $observedLevel, 'fan-out must run after the outermost commit');
        self::assertCount(1, $this->firedEvents);
    }

    public function testManagerWiredButNoScopeFansOutImmediately(): void
    {
        // Compatibility contract: CommandBus + TransactionManager wired,
        // dispatch NOT wrapped in a managed scope → fan-out happens during
        // dispatch, exactly like pre-2.22.
        $bus = new CommandBus(eventBus: $this->eventBus, transactions: $this->tx);
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): mixed => new CqrsEventResult(null, [new \stdClass()]));

        $bus->dispatch(new \stdClass());

        self::assertCount(1, $this->firedEvents, 'no managed scope open → immediate fan-out');
    }

    public function testDelegationMethodsProxyToInnerBus(): void
    {
        $inner = new CommandBus();
        $bus = new TransactionalCommandBus($inner, $this->tx);

        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): string => 'h');
        $bus->use(new class implements CqrsMiddlewareInterface {
            #[\Override]
            public function process(object $message, CqrsContext $context, \Closure $next): mixed
            {
                $result = $next($message, $context);
                assert(is_string($result));

                return 'wrapped-' . $result;
            }
        });
        self::assertFalse($bus->isFrozen());
        $bus->freeze();
        self::assertTrue($bus->isFrozen());

        self::assertSame('wrapped-h', $bus->dispatch(new \stdClass()), 'middleware registered via the decorator must run');

        try {
            $bus->register(self::class, static fn (object $c, CqrsContext $ctx): null => null);
            self::fail('Frozen inner bus must reject registration.');
        } catch (\LogicException) {
        }
    }

    public function testNestedDecoratedDispatchBecomesSavepoint(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx);
        $conn = $this->conn;
        $bus->register(TxNestedOuter::class, new readonly class($conn, $bus) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private TransactionalCommandBus $bus,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['outer']));
                $this->bus->dispatch(new \stdClass());

                return 'outer-done';
            }
        });
        $bus->register(\stdClass::class, new readonly class($conn) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['inner']));

                return 'inner-done';
            }
        });

        self::assertSame('outer-done', $bus->dispatch(new TxNestedOuter()));
        self::assertSame(['outer', 'inner'], $this->names(), 'nested dispatch commits within the outermost scope');
    }

    public function testIsolationOverrideSurfacesSqliteRejection(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, isolation: IsolationLevel::Serializable);
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): string => 'x');

        try {
            $bus->dispatch(new \stdClass());
            self::fail('SQLite must reject an isolation override.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('SQLite does not support isolation levels', $e->getMessage());
        }
    }

    /**
     * By-ref recording event bus; $onDispatch runs inside the listener so
     * tests can observe what the world looks like at fan-out time.
     *
     * @param list<object> $events
     */
    private function recordingBus(array &$events, ?\Closure $onDispatch = null): EventBusInterface
    {
        return new class($events, $onDispatch) implements EventBusInterface {
            /** @var list<object> */
            private array $events; // @phpstan-ignore-line written through the by-ref binding, read by the test

            /** @param list<object> $events */
            public function __construct(array &$events, private readonly ?\Closure $onDispatch)
            {
                $this->events = &$events;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($this->onDispatch instanceof \Closure) {
                    ($this->onDispatch)($event);
                }
                $this->events[] = $event;

                return $event;
            }

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(
                    bin2hex(random_bytes(16)),
                    0,
                    'c',
                ));
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}
        };
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

/**
 * Scenario command: outer step of the nested-dispatch test.
 */
final readonly class TxNestedOuter {}
