<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\TransactionalCommandBus;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionManager;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;
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

    public function testRetryingFlushPreservesHandlerWritesAndEmitsEventsOnce(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(
            new CommandBus(eventBus: $this->eventBus, transactions: $this->tx),
            $this->tx,
            $uow,
            retryPolicy: new UnitOfWorkRetryPolicy(initialDelayMs: 0),
        );
        $handlerCalls = 0;
        $flushCalls = 0;
        $bus->register(\stdClass::class, function (object $command, CqrsContext $context) use ($uow, &$handlerCalls, &$flushCalls): CqrsEventResult {
            ++$handlerCalls;
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['handler-write']));
            $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first-uow-write']));
            $uow->record(static function (ConnectionInterface $conn) use (&$flushCalls): void {
                ++$flushCalls;
                $conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second-uow-write']));
                if ($flushCalls === 1) {
                    throw new \PDOException('lock wait timeout', 1205);
                }
            });

            return new CqrsEventResult('ok', [new \stdClass()]);
        });

        self::assertSame('ok', $bus->dispatch(new \stdClass()));
        self::assertSame(1, $handlerCalls);
        self::assertSame(2, $flushCalls);
        self::assertSame(['handler-write', 'first-uow-write', 'second-uow-write'], $this->names());
        self::assertCount(1, $this->firedEvents);
        self::assertSame(0, $this->conn->transactionLevel());
        self::assertSame(0, $uow->pending());
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
