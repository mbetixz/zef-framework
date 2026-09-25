# Event Sourcing and transactional outbox

> Added in v2.19.0 (PR #35), hardened in v2.23.0 (PR #67). All classes live
> in the `Zef\Framework\EventSourcing` namespace and build on the Database
> Core ports from v2.18.0.

Event Sourcing stores every state change of an aggregate as an immutable
event in an append-only stream. You rebuild the aggregate by replaying its
events. ZEF ships the full pipeline:

- an **event store** with optimistic concurrency and a store-wide timeline,
- an **aggregate root** base class and a **repository** that persists and
  reconstitutes aggregates,
- **snapshots** that shortcut long replays,
- **projections** that build read models with checkpoints,
- a **transactional outbox** that delivers committed events to the event bus
  at least once,
- **event upcasting** that lets legacy streams replay against current code.

Use it when you need a full audit trail, temporal queries, or reliable
event delivery that commits atomically with your state changes. For plain
CRUD state, the Database Core repository is simpler.

---

## Building blocks

| Layer | Ports (Domain) | In-memory adapters (Application) | PDO adapters (Infrastructure) |
|-------|----------------|----------------------------------|-------------------------------|
| Event store | `EventStoreInterface` | `InMemoryEventStore` | `PdoEventStore` |
| Snapshots | `SnapshotStoreInterface` | `InMemorySnapshotStore` | `PdoSnapshotStore` |
| Checkpoints | `CheckpointStoreInterface` | `InMemoryCheckpointStore` | (bring your own, e.g. Redis/DB) |
| Outbox | `OutboxStoreInterface` | `InMemoryOutbox` | `PdoOutbox` |
| Projections | `ProjectionInterface` | `Projector` | |
| Upcasting | `UpcasterInterface` | `EventUpcaster` | |

Services: `AggregateRoot`, `AggregateRepository`, `SnapshotPolicy`,
`OutboxRecorder`, `OutboxRelay`.

---

## Event store

`EventStoreInterface` has three methods:

```php
public function appendToStream(string $aggregateType, string $aggregateId, int $expectedVersion, PendingEvent ...$events): array;
public function loadStream(string $aggregateType, string $aggregateId): array;
public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array;
```

- `appendToStream()` is atomic per call. The store assigns versions
  `expectedVersion + 1 … n` and returns the resulting `StoredEvent` list.
- `loadStream()` returns one aggregate's events ordered by version.
- `streamAll()` returns the store-wide timeline ordered by
  `globalSequence`. Projections use it to catch up.

Each `StoredEvent` carries `eventId`, `aggregateType`, `aggregateId`,
`version`, `globalSequence`, `eventType`, `payload`, `metadata`, and
`recordedAtUnixNano`.

### Optimistic concurrency

Pass the version you loaded as `$expectedVersion`. If another writer
appended in the meantime, the store throws `ConcurrencyException`. The
exception exposes `expectedVersion()` and `actualVersion()`. Reload the
aggregate and retry the command.

```php
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\PendingEvent;

$store = new InMemoryEventStore();

$store->appendToStream('shop.order', 'o-1', 0, new PendingEvent('order.placed', ['total' => 120]));

try {
    // Stale writer: still thinks the stream is at version 0.
    $store->appendToStream('shop.order', 'o-1', 0, new PendingEvent('order.cancelled'));
} catch (ConcurrencyException $e) {
    // $e->expectedVersion() === 0, $e->actualVersion() === 1
}
```

`PdoEventStore` backs this with `UNIQUE(aggregate_type, aggregate_id, version)`
and computes the global sequence as `MAX(global_sequence) + 1` inside the
transaction.

---

## Aggregates

Extend `AggregateRoot` and implement the abstract members:

| Member | Purpose |
|--------|---------|
| `static aggregateType(): string` | Stream type, e.g. `'shop.account'` |
| `aggregateId(): string` | Stream id |
| `apply(string $eventType, array $payload): void` (protected) | The only state-mutating switch |
| `snapshotState(): array` | State to store in a snapshot |
| `static restoreFromSnapshot(array $state, int $version): static` | Rebuild from a snapshot; call `seedVersion($version)` |
| `static createEmpty(string $aggregateId): static` | Blank instance used before a full replay |

Command methods call `recordEvent()`. It queues a pending event and applies
it immediately, so the aggregate is always its own first projection.

```php
use Zef\Framework\EventSourcing\AggregateRoot;

final class Account extends AggregateRoot
{
    private function __construct(private readonly string $id, private int $balance = 0) {}

    public static function open(string $id, int $initial): self
    {
        $account = new self($id);
        $account->recordEvent('account.opened', ['initial' => $initial]);

        return $account;
    }

    public function deposit(int $amount): void
    {
        $this->recordEvent('account.deposited', ['amount' => $amount]);
    }

    public static function aggregateType(): string { return 'shop.account'; }
    public function aggregateId(): string { return $this->id; }

    public function snapshotState(): array
    {
        return ['id' => $this->id, 'balance' => $this->balance];
    }

    public static function restoreFromSnapshot(array $state, int $version): static
    {
        $account = new self((string) $state['id'], (int) $state['balance']);
        $account->seedVersion($version);

        return $account;
    }

    public static function createEmpty(string $aggregateId): static
    {
        return new self($aggregateId);
    }

    protected function apply(string $eventType, array $payload): void
    {
        match ($eventType) {
            'account.opened' => $this->balance = (int) $payload['initial'],
            'account.deposited' => $this->balance += (int) $payload['amount'],
        };
    }
}
```

### Stream continuity guard

Since v2.23.0, `applyStored()` requires each replayed event to arrive at
exactly `version + 1`. A gap or regression (truncated export, hand-edited
row) throws `EventSourcingException`. The message names the event id, the
expected version, the actual version, and the version reached. The
aggregate's state and version stay untouched when the guard fires.

---

## Repository

`AggregateRepository` wires the stores together:

```php
public function __construct(
    EventStoreInterface $store,
    ?SnapshotStoreInterface $snapshots = null,
    ?SnapshotPolicy $policy = null,
    ?OutboxRecorder $outbox = null,
    ?ConnectionInterface $connection = null,
    ?\Closure $clock = null,
    ?EventUpcaster $upcasters = null,
)
```

- `persist($aggregate)` appends pending events, calls `markCommitted()`,
  saves a snapshot when the policy asks for one, and enqueues outbox entries
  when an `OutboxRecorder` is configured. It returns the committed
  `StoredEvent` list.
- `find($class, $id)` restores from the latest snapshot (if any), then
  replays the remaining events. It returns `null` for unknown streams.
- `findOrFail($class, $id)` throws `AggregateNotFoundException` instead.
  Both are generic (`@template T of AggregateRoot`) since v2.23.0, so
  static analysis sees the concrete type.

```php
$repository = new AggregateRepository($store);

$account = Account::open('acc-1', 100);
$repository->persist($account);

$account = $repository->findOrFail(Account::class, 'acc-1');
$account->deposit(50);
$repository->persist($account); // ConcurrencyException if another writer won
```

Passing a `SnapshotPolicy` without a snapshot store throws
`EventSourcingException`.

### Atomic persist with PDO

When `PdoEventStore`, `PdoSnapshotStore`, and `PdoOutbox` share one
`ConnectionInterface`, pass that connection to the repository (and to
`OutboxRecorder`). `persist()` then commits events, snapshot, and outbox
entries in one transaction. The PDO adapters join an already-open
transaction instead of starting a nested one.

---

## Snapshots

`SnapshotPolicy` decides when `persist()` writes a snapshot:

```php
SnapshotPolicy::default();   // every 100 versions (SnapshotPolicy::DEFAULT_INTERVAL)
SnapshotPolicy::every(50);   // every 50 versions
```

### No-regress snapshots

Since v2.23.0, a snapshot never moves backwards. `InMemorySnapshotStore`
and `PdoSnapshotStore` discard a `save()` whose version is older than the
stored one, so a slow writer can't overwrite a newer snapshot. Saving the
same version again is allowed and refreshes the state payload.

---

## Projections

Implement `ProjectionInterface` to build a read model:

```php
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\StoredEvent;

final class BalanceProjection implements ProjectionInterface
{
    public array $balances = [];

    public function projectionId(): string { return 'balances'; }

    public function handles(): array { return ['account.opened', 'account.deposited']; }

    public function handle(StoredEvent $event): void
    {
        $delta = (int) ($event->payload['initial'] ?? $event->payload['amount'] ?? 0);
        $this->balances[$event->aggregateId] = ($this->balances[$event->aggregateId] ?? 0) + $delta;
    }
}
```

`Projector` reads `streamAll()` in pages of up to 10,000 events, starting
right after each projection's checkpoint:

```php
$projector = new Projector($store, new InMemoryCheckpointStore(), [new BalanceProjection()]);

$projector->run();                          // every projection, in registration order, until caught up
$projector->runProjection('balances', 500); // one projection, stop after 500 applied events
```

- The checkpoint advances after each observed event, including events the
  projection doesn't handle. If a handler throws, the event is retried on the
  next run. Delivery is at least once, so handlers must be idempotent.
- `batchLimit` stops after exactly that many applied events.
- Projection ids must be unique. The constructor requires at least one
  projection.

---

## Transactional outbox

The outbox turns committed events into bus messages without losing them
when dispatch fails.

```
AggregateRepository::persist() → OutboxRecorder → outbox table → OutboxRelay → EventBus → listeners
```

### Recording

`OutboxRecorder` creates one outbox entry per stored event. It adds the
stream coordinates to the entry metadata (`eventId`, `aggregateType`,
`aggregateId`, `version`, `globalSequence`). These system keys override
user metadata with the same name.

```php
$outbox = new PdoOutbox($connection);
$repository = new AggregateRepository(
    store: new PdoEventStore($connection),
    snapshots: new PdoSnapshotStore($connection),
    outbox: new OutboxRecorder($outbox, $connection),
    connection: $connection,
);
```

### Relaying

`OutboxRelay` dispatches each due entry as an `OutboxMessage` on the
`EventBusInterface`. Listeners register for `OutboxMessage::class` and switch
on `$message->entry->messageType`.

```php
$relay = new OutboxRelay(
    outbox: $outbox,
    bus: $eventBus,
    maxAttempts: 5,        // OutboxRelay::DEFAULT_MAX_ATTEMPTS
    backoffBaseMs: 1_000,  // OutboxRelay::DEFAULT_BACKOFF_BASE_MS
    backoffCapMs: 60_000,  // OutboxRelay::DEFAULT_BACKOFF_CAP_MS
);

$dispatched = $relay->relay(100); // run from a worker loop or scheduler
```

- On success, the relay calls `markProcessed()`.
- On failure, it calls `markFailed()` and reschedules the entry with
  exponential backoff: `backoffBaseMs * 2^(attempt - 1)`, capped at
  `backoffCapMs`. `retryDelayMs($attempt)` returns the computed delay.
- After `maxAttempts` failures, the entry becomes a dead letter (status
  `failed`).
- The constructor rejects `maxAttempts < 1`, `backoffBaseMs < 1`, and
  `backoffCapMs < backoffBaseMs`.

Entries are processed FIFO by `(createdAt, id)`. Delivery is at least once,
so consumers must be idempotent.

### Dead letters and requeue

```php
$dead = $relay->deadLetters(100);          // inspect failed entries and $entry->lastError
// ... fix the root cause ...
$requeued = $relay->requeueDeadLetters(100); // since v2.23.0
```

`requeueDeadLetters()` calls `OutboxStoreInterface::requeue($id, ?$nextAttemptAtUnixNano)`
for up to `$limit` dead letters. `requeue()`:

- sets status back to `pending`, resets `attempts` to 0, and schedules the
  entry at the given time (the adapter's clock when `null`),
- keeps `lastError` visible until the next dispatch attempt overwrites it,
- throws when the entry isn't `failed`, the id is unknown, or the timestamp
  is negative.

Only requeue after you have fixed the cause. Otherwise the entries fail
again and return to the dead-letter list.

---

## Event upcasting

Since v2.23.0, you can evolve event schemas without rewriting stored data.
An upcaster transforms a legacy event in memory during replay. The stored
bytes never change.

Implement `UpcasterInterface`:

```php
use Zef\Framework\EventSourcing\StoredEvent;
use Zef\Framework\EventSourcing\UpcasterInterface;

final class DepositV1ToV2 implements UpcasterInterface
{
    public function eventTypes(): array
    {
        return ['account.deposited.v1'];
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        return new StoredEvent(
            $event->eventId,
            $event->aggregateType,
            $event->aggregateId,
            $event->version,
            $event->globalSequence,
            'account.deposited',
            ['amount' => intdiv((int) $event->payload['cents'], 100)],
            $event->metadata,
            $event->recordedAtUnixNano,
        );
    }
}
```

Rules:

- `upcast()` must be pure. It may rename the event and reshape `payload`
  and `metadata`.
- Event id, aggregate type and id, version, global sequence, and recorded-at
  are frozen. `EventUpcaster` checks each field and throws on a change.

Register upcasters in an `EventUpcaster` and pass it to the repository:

```php
$upcasters = new EventUpcaster(new DepositV1ToV2(), new SomeOtherUpcaster());

$repository = new AggregateRepository($store, upcasters: $upcasters);
```

- Upcasters for the same type run in registration order.
- A renamed event continues through the upcasters of its new type, so
  v1 → v2 → v3 chains compose.
- `EventUpcaster::MAX_HOPS` (16) limits the chain. A chain of 16 hops
  completes. A 17th hop throws, which catches rename cycles.
- Events without a registered upcaster pass through unchanged.
- The repository applies upcasting to full replays and to the tail replay
  after a snapshot.

---

## In-memory vs PDO adapters

| | In-memory | PDO |
|---|-----------|-----|
| Use for | Tests, prototypes, single-process demos | Production |
| Durability | Process lifetime only | Database (MySQL, PostgreSQL, SQLite) |
| Atomic persist | N/A | Yes, with a shared `ConnectionInterface` |
| Schema | None | `createSchema()` (portable DDL, safe to rerun) |
| Default table | N/A | `zef_events`, `zef_snapshots`, `zef_outbox` |
| Clock | Optional `\Closure(): int` (nanoseconds) | Optional `\Closure(): int` (event store, outbox) |

Both implement the same ports, so you can swap them without changing
aggregates, projections, or the repository.

```php
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;

$connection = new PdoConnection(ConnectionConfig::fromArray([
    'driver' => 'sqlite',
    'dbname' => __DIR__ . '/var/events.sqlite',
]));

$events = new PdoEventStore($connection);        // table: zef_events
$snapshots = new PdoSnapshotStore($connection);  // table: zef_snapshots
$outbox = new PdoOutbox($connection);            // table: zef_outbox

$events->createSchema();
$snapshots->createSchema();
$outbox->createSchema();
```

---

## Upgrading to v2.23.0

`PdoEventStore::createSchema()` now adds two store-wide unique constraints:

- `UNIQUE(event_id)`: duplicate event ids fail instead of being applied twice.
- `UNIQUE(global_sequence)`: concurrent writers can compute the same
  `MAX(global_sequence) + 1` under a repeatable-read snapshot. With the index,
  the losing writer fails and retries instead of reusing a sequence number
  that projections checkpoint on.

`CREATE TABLE IF NOT EXISTS` does not alter existing tables. If you created
your events table before v2.23.0, add both indexes manually. Replace
`<table>` with your table name (default `zef_events`):

```sql
CREATE UNIQUE INDEX uq_<table>_event_id ON "<table>" ("event_id");
CREATE UNIQUE INDEX uq_<table>_global   ON "<table>" ("global_sequence");
```

Check for existing duplicates in these columns first. The index creation
fails if any exist.

Other v2.23.0 changes are additive:

- `OutboxStoreInterface` gained `requeue()`. Custom outbox adapters must
  implement it.
- `AggregateRepository` accepts an optional `EventUpcaster`.
- Replay now rejects version gaps, and snapshot stores refuse older versions.

See [`CHANGELOG-v2.19.0.md`](CHANGELOG-v2.19.0.md) and
[`CHANGELOG-v2.23.0.md`](CHANGELOG-v2.23.0.md) for full release notes.
