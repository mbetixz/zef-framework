# ZEF Framework — CHANGELOG v2.19.0

**Tema paket besar #2: Event Sourcing + Transactional Outbox** — event store append-only,
aggregate root event-sourced, snapshot policy, catch-up projections dengan checkpoint,
dan outbox transaksional yang dibangun di atas port Database Core (v2.18.0).

## Ringkasan

- **28 kelas baru** dalam tiga layer hexagonal:
  - `src/Domain/EventSourcing/` (16): `EventSourcingException`, `ConcurrencyException`,
    `AggregateNotFoundException`, `EventGrammar`, `EventJson`, `PendingEvent`, `StoredEvent`,
    `Snapshot`, `OutboxEntry`, `OutboxMessage`, `AggregateRoot`, `EventStoreInterface`,
    `SnapshotStoreInterface`, `CheckpointStoreInterface`, `ProjectionInterface`,
    `OutboxStoreInterface`.
  - `src/Application/EventSourcing/` (9): `InMemoryEventStore`, `InMemorySnapshotStore`,
    `InMemoryCheckpointStore`, `InMemoryOutbox`, `SnapshotPolicy`, `AggregateRepository`,
    `Projector`, `OutboxRecorder`, `OutboxRelay`.
  - `src/Infrastructure/EventSourcing/` (4): `PdoEventStore`, `PdoSnapshotStore`, `PdoOutbox`,
    `RowCast`.
- **142 tes baru** (6 file + fixture) — total suite 1.794 tes / 17.914 asersi.

## Fitur

### Event store (append-only)
- `EventStoreInterface::appendToStream()` — atomik per panggilan; versi stream
  ditetapkan store (`expectedVersion+1..n`), mismatch → `ConcurrencyException`
  (expected/actual terekspos).
- `loadStream()` (per-aggregate, urut versi) dan `streamAll()` (timeline store-wide
  urut `globalSequence`, untuk catch-up projection).
- `PdoEventStore` di atas `ConnectionInterface` v2.18.0: backstop konkurensi via
  `UNIQUE(aggregate_type, aggregate_id, version)`, global sequence via
  `MAX(global_sequence)+1` di dalam transaksi (portabel MySQL/SQLite/PostgreSQL).
- **Ambient transaction**: adapter PDO ikut transaksi yang sudah terbuka (join) —
  persist repository meng-commit event + snapshot + outbox **atomik** ketika
  ketiganya berbagi satu koneksi.
- `createSchema()` portable DDL per adapter (aman dijalankan berulang).

### Aggregate event-sourced
- `AggregateRoot`: `recordEvent()` → pending + apply langsung (aggregate adalah
  proyeksi pertama dirinya); replay via `applyStored()` dengan guard versi
  strictly-increasing; `pendingVersion()` = versi committed terakhir.
- `AggregateRepository::persist()` — append + `markCommitted()` + snapshot bila
  `SnapshotPolicy` menghendaki (interval, default 100) + enqueue outbox opsional;
  `find()/findOrFail()` — restore dari snapshot lalu replay event setelahnya.
- Kontrak snapshot: `snapshotState()` + `restoreFromSnapshot()` + `createEmpty(id)`.
- `ConcurrencyException` naik saat writer basi — caller reload + retry.

### Projection / read model
- `ProjectionInterface` (id, `handles()`, `handle()`); `Projector` membaca timeline
  berhalaman (`EventGrammar::MAX_PAGE` = 10.000) mulai tepat setelah checkpoint,
  checkpoint naik setelah setiap event berhasil di-handle (at-least-once),
  `batchLimit` menghentikan tepat pada jumlah event yang di-apply.
- `InMemoryCheckpointStore` — port `CheckpointStoreInterface` siap di-backing Redis/DB.

### Transactional outbox
- `OutboxStoreInterface` + `InMemoryOutbox` / `PdoOutbox`: FIFO deterministik
  `(createdAt, id)`, `due(limit, now)` vs `failed(limit)` (dead letter),
  `markFailed` (attempts+1, tetap pending, retry sesuai jadwal) vs
  `markDead` (attempts+1, status failed permanen).
- `OutboxRecorder` — satu entry per stored event; metadata diperkaya koordinat
  stream (`eventId`, `aggregateType`, `aggregateId`, `version`, `globalSequence`),
  kunci sistem menang atas metadata user.
- `OutboxRelay` — dispatch `OutboxMessage` ke `EventBusInterface`; sukses →
  `markProcessed`; gagal → backoff eksponensial `base * 2^(attempt-1)` (dibatasi
  `backoffCapMs`, anti-overflow) sampai `maxAttempts` → dead letter.
- Endpoint-to-endpoint: `AggregateRepository(persist)` → `OutboxRecorder` →
  `OutboxRelay` → bus → listener — at-least-once, konsumen wajib idempoten.

## Kualitas (disiplin "Paket Besar")

- **Gate mutasi zona EventSourcing**: 702 mutan → **MSI 92% / covered 92%**
  (646 killed + 1 timeout; di atas gate 85/90). Sisa 56 escape ditriase
  terdokumentasi: default hydrate `?? 0` tak terjangkau (kolom selalu dipilih
  eksplisit), pasangan clock realtime yang bergeser konsisten, CastInt redundan
  di SQLite, guard defensif `countPending`, dan satu trio clamp `min()` yang
  ekuivalen.
- Kurikulum adversarial dua ronde (`EdgeMatrixEventSourcingTest`,
  `EdgeMatrixEventSourcingRound2Test`): batas grammar ±1 byte, tebing kedalaman
  JSON 512/513, urutan validasi, pembakaran global sequence, integritas rollback
  (SQLite trigger kill-switch), transaksi ambient, FIFO `(createdAt, id)`
  dengan clock terbalik, halaman penuh MAX_PAGE, backoff overflow, dan bentuk
  DDL via `PRAGMA table_info`.
- **Regresi penuh 13 tool hijau**: lint 477:0 · self-test 501/501 · PHPUnit
  1.794 tes / 17.914 asersi (5 skip kondisional) · PHPStan max 0 · PHPCS 0 ·
  CS-Fixer 0 · Rector 0 · Deptrac 0-0 · validate/audit OK.

## Catatan upgrade

- Versi `ZefVersion::VERSION` → **2.19.0**.
- Tanpa perubahan breaking pada zona lama; zona EventSourcing sepenuhnya additif
  dan hanya bergantung pada Database Core (v2.18.0).
