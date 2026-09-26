<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
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

    public function testFailedFlushDoesNotCacheResultAndRetryCommits(): void
    {
        $uow = new UnitOfWork();
        $calls = 0;
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx, $uow);
        $bus->register(\stdClass::class, function () use ($uow, &$calls): string {
            ++$calls;
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['direct']));
            $uow->recordQuery(SqlQuery::raw($calls === 1
                ? 'INSERT INTO missing_table (name) VALUES (\'fail\')'
                : 'INSERT INTO t (name) VALUES (\'deferred\')'));

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'flush-retry-key');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected flush failure.');
        } catch (QueryException) {
        }
        self::assertSame([], $this->names());
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(['direct', 'deferred'], $this->names());
    }

    public function testFailedCommitDoesNotCacheResult(): void
    {
        // A real deferred SQLite constraint fails only at COMMIT. The
        // connection's commit-failure rollback is a separate concern, so
        // explicitly clean up the failed transaction before retrying here.
        $this->conn->execute(SqlQuery::raw('PRAGMA foreign_keys = ON'));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE parent (id INTEGER PRIMARY KEY)'));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE child (parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)'));
        $calls = 0;
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $bus->register(\stdClass::class, function () use (&$calls): string {
            ++$calls;
            $this->conn->execute(SqlQuery::raw('INSERT INTO child (parent_id) VALUES (1)'));

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'commit-retry-key');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected commit failure.');
        } catch (TransactionException) {
            $this->conn->rollBack();
        }
        self::assertSame([], $this->conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));
        $this->conn->execute(SqlQuery::raw('INSERT INTO parent (id) VALUES (1)'));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertCount(1, $this->conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));
    }

    public function testHookFailureAfterCommitKeepsCachedResult(): void
    {
        $calls = 0;
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $bus->register(\stdClass::class, function () use (&$calls): string {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));
            $this->tx->afterCommit(static function (): never {
                throw new \RuntimeException('hook failure');
            });

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'hook-failure-key');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected hook failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('hook failure', $error->getMessage());
        }
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
        self::assertSame(['committed'], $this->names());
    }

    public function testEventFailureAfterCommitKeepsCachedResultWithoutExplicitInnerManager(): void
    {
        $calls = 0;
        $eventCalls = 0;
        $events = [];
        $bus = new TransactionalCommandBus(new CommandBus(
            new InMemoryIdempotencyStore(),
            eventBus: $this->recordingBus($events, function () use (&$eventCalls): never {
                ++$eventCalls;
                self::assertSame(0, $this->conn->transactionLevel());

                throw new \RuntimeException('event failure');
            }),
        ), $this->tx);
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('committed')"));

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'event-failure-key');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected event failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('event failure', $error->getMessage());
        }
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
        self::assertSame(1, $eventCalls);
        self::assertSame(['committed'], $this->names());
    }

    public function testKeyedDispatchInsideOuterScopeFailsBeforeExecutingHandler(): void
    {
        $calls = 0;
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $bus->register(\stdClass::class, static function () use (&$calls): string {
            ++$calls;

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'nested-command-key');

        try {
            $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context));
            self::fail('Expected unsafe nested idempotency to be rejected.');
        } catch (\LogicException $error) {
            self::assertSame('Idempotent commands must own the outermost managed transaction.', $error->getMessage());
        }
        self::assertSame(0, $calls);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
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
