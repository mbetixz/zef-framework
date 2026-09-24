# v2.23.0 — Event Sourcing Hardening

Released: 2026-09-25 (target; PR `feat/v2.23.0-es-hardening`)

Event Sourcing shipped in earlier releases (event store, snapshots, outbox,
repository). v2.23.0 hardens the replay-and-delivery pipeline against the
failure modes that only surface in production: truncated or hand-edited
streams, concurrent snapshot writers, dead letters that stay dead forever,
duplicate event identities under database read snapshots, and legacy event
schemas that no longer match the aggregate code that must replay them.

## 1. Stream continuity guard (strict version steps)

`AggregateRoot::applyStored()` previously rejected only non-increasing
versions; a stream that LOST an event (truncated export, hand-edited row,
rogue writer) would silently replay with a gap and fabricate a wrong
aggregate state. The guard now requires every stored event to arrive at
exactly `version + 1`:

- a gap or a regression aborts the replay with `EventSourcingException`
  naming the event id, the expected stored version, the actual version and
  the version the aggregate reached before the abort — everything an
  operator needs to locate the corruption;
- state and version are untouched when the guard fires (the aggregate is
  not left half-replayed).

## 2. Snapshot regression guard (CAS semantics)

A slow writer could previously clobber a newer snapshot saved concurrently
between this aggregate's load and save. `SnapshotStoreInterface` now
formalises the concurrency contract — a snapshot may never move BACKWARDS:

- `InMemorySnapshotStore::save()` discards a save whose version is strictly
  older than the stored one;
- `PdoSnapshotStore::save()` checks the stored version first and refuses to
  regress (the portable upsert now reads before it writes);
- saving the SAME version again is allowed — an idempotent refresh whose
  fresher state payload wins (covered for both stores);
- both stores continue to join ambient transactions: a save inside an
  open transaction adds no nested begin/commit of its own (regression
  tests assert the caller keeps control of the atomic boundary).

## 3. Outbox dead-letter requeue

Dead letters were terminal. The outbox port gains one method —
`OutboxStoreInterface::requeue(string $id, ?int $nextAttemptAtUnixNano = null)`:

- status returns to `pending`, the attempt budget resets to 0 and the entry
  becomes eligible again at the given timestamp (the adapter's clock when
  null, per the port contract);
- the last error stays visible on the entry for post-mortem reading until
  the next dispatch attempt overwrites it;
- requeueing an entry that is not `failed` is a caller bug and throws with
  the entry's current status; unknown ids and negative timestamps are
  rejected before the database is touched;
- `PdoOutbox::requeue()` reports an entry that VANISHES between the update
  and the re-read (external reaper, misbehaving trigger) instead of
  fabricating one;
- `OutboxRelay::requeueDeadLetters(int $limit = 100)` requeues up to 100
  dead letters per call — use after fixing the root cause; the `deadLetters()`
  default batch of 100 is now pinned by tests.

## 4. Event store schema backstops

`PdoEventStore::createSchema()` adds two store-wide unique constraints:

- `UNIQUE(event_id)` — an event id is a global identity, not a per-stream
  one; duplicates now fail loudly instead of silently double-applying;
- `UNIQUE(global_sequence)` — the in-transaction `MAX(global_sequence) + 1`
  computation can race under a database's repeatable-read snapshot; without
  the index the loser would silently double-assign the projection currency
  that checkpointed consumers rely on. With it, the loser fails and the
  caller retries.

**Upgrade note:** `CREATE TABLE IF NOT EXISTS` does not alter existing
tables. Deployments created before v2.23.0 should add both indexes manually,
e.g.:

```sql
CREATE UNIQUE INDEX uq_<table>_event_id ON "<table>" ("event_id");
CREATE UNIQUE INDEX uq_<table>_global   ON "<table>" ("global_sequence");
```

Both constraints carry stable, testable names (verified against the DDL in
tests).

## 5. Event upcasting (schema evolution during replay)

Legacy streams used to replay only while the event schema stayed frozen.
v2.23.0 introduces upcasting:

- `UpcasterInterface` (Domain port) — `eventTypes()` declares the types an
  upcaster transforms; `upcast()` MUST be pure and may rename the event or
  reshape `payload` / `metadata`, but the stream identity (event id,
  aggregate coordinates, version, global sequence, recorded-at) is FROZEN;
- `EventUpcaster` (Application registry) — chains upcasters per event type
  in registration order; an upcaster may rename an event into a type that
  owns its own upcasters, so multi-step migrations (v1→v2→v3) compose
  naturally. A rename cycle is rejected after a hard hop budget
  (`EventUpcaster::MAX_HOPS = 16`), and the budget is a ceiling, not a
  suggestion: a legal chain of exactly 16 hops completes, 17 is rejected;
- identity violations throw instead of silently corrupting the replay —
  every frozen field is checked individually;
- events without registered upcasters pass through untouched (zero cost for
  streams already in the current schema);
- `AggregateRepository` accepts the registry as an optional constructor
  argument and applies it during both full reconstitution and
  snapshot-seeded tail replay; `find()` / `findOrFail()` are now
  generic (`@template T of AggregateRoot`), so a replayed aggregate's
  concrete methods are visible to static analysis without casts.

## 6. Mutation zone `es-hardening`

A new canonical mutation zone freezes the hardening surface (aggregate
root, upcasting, repository, outbox stores/relay, PDO event/snapshot
stores): **MSI 96.10% / covered MSI 96.28% over 538 mutants**, above the
95% target and the `app-db-tx` precedent. Accepted equivalents (documented
in the evidence file): default-clock ±1ns arithmetic, `fetchOne(...limit 1→2)`
on single-row reads, the defensive `countPending()` non-scalar guard
(unreachable over SQL `COUNT(*)`), StoredEvent's own constructor
re-validation behind the store boundary, and savepoint round-trips in the
ambient-transaction paths.

## Non-goals

- Projection rebuild tooling and checkpoint management stay out of scope —
  the global-sequence backstop makes future checkpoint consumers safe, but
  the projections themselves are a separate roadmap item.
- Upcasting is not a persistence-layer migration: stored bytes never change;
  transformation happens in memory during replay only.
- The outbox remains best-effort in-process; the relay's dead-letter
  requeue assumes a human (or orchestrator) fixed the root cause first.
