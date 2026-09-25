# ZEF Framework — CHANGELOG v2.23.0

Rilis ini menggabungkan dua jalur kerja yang tiba pada minor yang sama:

- **Bagian A — Config v2 future enhancements (issue #60)**: observability
  pipeline secrets, cache index radix OPcache-friendly, memoization pattern
  query, dan jalur migrasi versi skema (semuanya additive dan opt-in; boot
  default tidak berubah perilaku).
- **Bagian B — Event Sourcing Hardening**: hardening pipeline
  replay-and-delivery — guard stream continuity, snapshot no-regress,
  dead-letter requeue, backstop schema event store, dan event upcasting.

Semua additive terhadap API v2.22.0; `ZefVersion::VERSION = '2.23.0'`.

## Bagian A — Config v2 future enhancements (issue #60)

**Config v2 future enhancements (issue #60)** — keempat item roadmap dari
review v2.21.1 kini terimplementasi penuh: observability pipeline secrets,
cache index radix OPcache-friendly, memoization pattern query, dan jalur
migrasi versi skema. Semua additive dan opt-in; boot default tidak berubah
perilaku sama sekali.

##### Ringkasan

- **`ConfigMetricsInterface` (port, `src/Domain/Config/`)** — port
  observability semantik untuk pipeline secrets: `secretRetry()`,
  `secretResolved()`, dan `secretStaleFallback()` dipancarkan tepat di
  titik kejadiannya di dalam `ResilientSecretsProvider` (sebelum backoff
  sleep, setelah provider menjawab, dan saat stale value disajikan).
  Kontrak label-nya ketat: hanya NAMA key (dotted path) dan nama provider —
  nilai secret TIDAK PERNAH menjadi label (dijaga test khusus).

- **`NullConfigMetrics` (null-object, `src/Domain/Config/`)** — default
  zero-cost; provider tetap dependency-free ketika telemetry tidak di-bind.

- **`MeterConfigMetrics` (adapter, `src/Infrastructure/Config/`)** — memetakan
  port ke `MeterInterface` yang sudah ada dengan kosakata metric framework:
  `zef.config.secrets.retries.total{provider, key}`,
  `zef.config.secrets.success.total{provider}`,
  `zef.config.secrets.fallback.total{provider}`. Label dibatasi 96 karakter
  `[A-Za-z0-9._:-]` — di luar itu collapse ke `[other]` (meniru bounding
  service-id `CounterMeter`), dan `MAX_SERIES` counter tetap menjadi
  backstop kardinalitas terakhir.

- **`ResilientSecretsProvider` (perubahan kecil, backward-compatible)** —
  parameter constructor baru opsional `?ConfigMetricsInterface $metrics = null`;
  label provider default = short class name inner provider (dihitung sekali,
  lazy). Tanpa parameter, perilaku identik dengan v2.22.0.

- **`RadixTreeCache` (infrastruktur baru, `src/Infrastructure/Config/`)** —
  cache disk untuk index radix pattern-query:
  - format: satu envelope `serialize()` berisi stempel `ZefVersion`,
    fingerprint SHA-256 dari value tree, dan tree itu sendiri — OPcache-
    neutral by design (menangnya melewatkan BUILD, bukan parse);
  - invalidasi dua lapis per pembacaan: versi framework (perubahan bentuk
    class antar rilis) + fingerprint nilai (config berubah = cache hangus);
  - miss lunak: file absen/tidak terbaca/korup/versi atau fingerprint tua →
    rebuild, tidak pernah gagal boot (`@unserialize` + `allowed_classes`
    ketat `[ConfigRadixTree, ConfigRadixNode]` sebagai bantalan keamanan);
  - penulisan: atomik temp+rename dengan chmod diterapkan SEBELUM rename
    (default 0600) — kontrak keamanan yang sama dengan `ConfigCompiler`,
    karena payload berisi nilai resolved termasuk secrets;
  - API: `hydrate(values, file)` untuk boot (baca-atau-build, tidak pernah
    menulis), `store(values, file)` untuk pipeline compile, dan
    `read(file, values)` untuk tooling.

- **`Config` (perubahan kecil, backward-compatible)** — constructor menerima
  index prebuilt opsional (`?ConfigRadixTree $cachedIndex = null`);
  `RadixTreeCache` menghidrasinya tanpa rebuild. Tanpa parameter, index
  dibangun eager seperti sebelumnya.

- **`PatternQueryCache` (domain, `src/Domain/Config/`)** — memo in-process
  untuk `Config::query()` berulang: kunci = string pattern persis
  (kardinalitas dibatasi jumlah pattern berbeda, bukan nilai data);
  invalidasi struktural (Config immutable + cache terikat SATU instance bag —
  re-freeze = instance baru = cache baru, stale mustahil by construction);
  copy-on-write menjaga series cache dari mutasi pemanggil; `clear()` untuk
  worker long-lived; `stats()` untuk test/diagnostik. `subtree()` dan
  `longestMatch()` sengaja tidak di-memo (kunci data tak terbatas).

- **`ConfigSchema` (perubahan kecil, backward-compatible)** — field
  `public int $version` (default `ConfigSchema::CURRENT_VERSION = 1`) +
  validasi positif. Bump HANYA saat ada perubahan breaking nyata.

- **`ConfigMigrator` (application, `src/Application/Config/`)** — registry
  langkah migrasi `callable(array): array` per versi TARGET, dijalankan
  BERURUTAN selama load:
  - hanya ASCENDING (downgrade ditolak lantang — lossy by nature);
  - hop tanpa langkah terdaftar gagal fast (validasi data lama terhadap
    skema baru secara diam-diam adalah bug);
  - beberapa langkah per versi berjalan sesuai urutan registrasi;
  - langkah melihat data MENTAH sebelum resolusi secrets — rename/
    restrukturisasi, bukan resolusi `%secret:...%`.

- **`ConfigLoader` (perubahan kecil, backward-compatible)** — parameter opsional
  `?ConfigMigrator $migrator` + `?int $sourceSchemaVersion`; migrasi berjalan
  pada tree mentah gabungan SEBELUM secrets/validasi/defaults. Kombinasi
  param yang tidak bermakna (migrator tanpa skema, versi tanpa migrator,
  versi non-positif) ditolak di constructor.

- **`Application` (kernel wiring)** — binding container
  `ConfigMetricsInterface::class` → `MeterConfigMetrics` (singleton,
  dependensi `MeterInterface`), plus `setConfigMigrator($m, ?$fromVersion)`
  sebelum boot.

- **`ZefVersion`** — `2.22.0` → `2.23.0`.

##### Motivasi

Issue #60 melacak empat peningkatan yang diidentifikasi saat review v2.21.1.
P1 menutup blind spot produksi: retry/fallback secrets yang tak teramati
berarti insiden "config boot lambat/korup" tidak punya sinyal. P2 melanjutkan
filosofi compiled-boot (`ConfigCompiler` menghapus pipeline sumber;
`RadixTreeCache` menghapus rebuild index). P3 meng-cover hot path yang
mengiterasi pattern sama per request. P4 menyiapkan jalur evolusi skema
sebelum dibutuhkan — mekanisme teruji, tanpa hop terdaftar selama semua
skema masih versi 1.

##### Kompatibilitas

- Semua API baru additive dan opt-in; signature lama tidak berubah.
- Boot default: tanpa metrics binding, tanpa cache radix, tanpa migrator —
  perilaku byte-identical dengan v2.22.0 (48 test baru + 2274 test lama
  hijau, PHPStan level maksimum bersih, deptrac 0 pelanggaran).
- File cache radix menyimpan secrets resolved — sensitivitas sama dengan
  `config.php` terkompilasi (chmod 0600, `var/cache/` di `.gitignore`).

##### Testing

Empat file test baru (`tests/Unit/ConfigV2{SecretsMetrics,RadixCache,
PatternQueryCache,SchemaVersioning}Test.php`, 48 test / 95 assertion):
kosakata metric + bounding label + "nilai secret tidak pernah jadi label",
round-trip fidelity index cache + invalidasi fingerprint/versi + miss lunak
korupsi + izin file 0600 + residu temp nol, hit/miss memo + anti-poisoning
CoW + invalidasi struktural, dan tangga migrasi multi-hop + penolakan
downgrade + integrasi loader (migrasi sebelum resolusi secrets).

##### Interaksi quality gate

Run CI pertama PR ini melaporkan tepat SATU temuan SAST yang belum
terdisposisi: `php.lang.security.unserialize-use` pada jalur baca
`RadixTreeCache` (gate PHP SAST, pass *production source @ WARNING*, yang
menjadi blocking sejak 2026-09-24). Disposisinya mengikuti urutan terdaftar
docs/security/php-sast.md §7 — bukan melonggarkan gate: provenance
ditelusuri (file cache yang ditulis kelas ini sendiri di bawah kontrak
rename atomik + 0600), blast radius dibatasi (`allowed_classes` ketat atas
dua class `final readonly` tanpa magic method — gadget chain object
injection tidak mungkin dimulai), justifikasi ditulis, marker `#nosemgrep`
diletakkan tepat di baris call, dan entri register #48–#49 ditambahkan ke
dokumen. Tidak ada rule yang dimatikan, tidak ada path yang dikecualikan;
invokasi yang sama kini exit 0 dengan temuan tetap terekam di SARIF
yang dihasilkan. Marker `unlink` di `write()` (entri #49) mengikuti pola
entri 3–5 dan 25.

Menutup issue #60 (P1–P4).

## Bagian B — Event Sourcing Hardening

# v2.23.0 — Event Sourcing Hardening

Released: 2026-09-25 (target; PR `feat/v2.23.0-es-hardening`)

Event Sourcing shipped in earlier releases (event store, snapshots, outbox,
repository). v2.23.0 hardens the replay-and-delivery pipeline against the
failure modes that only surface in production: truncated or hand-edited
streams, concurrent snapshot writers, dead letters that stay dead forever,
duplicate event identities under database read snapshots, and legacy event
schemas that no longer match the aggregate code that must replay them.

##### 1. Stream continuity guard (strict version steps)

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

##### 2. Snapshot regression guard (CAS semantics)

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

##### 3. Outbox dead-letter requeue

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

##### 4. Event store schema backstops

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

##### 5. Event upcasting (schema evolution during replay)

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

##### 6. Mutation zone `es-hardening`

A new canonical mutation zone freezes the hardening surface (aggregate
root, upcasting, repository, outbox stores/relay, PDO event/snapshot
stores): **MSI 96.10% / covered MSI 96.28% over 538 mutants**, above the
95% target and the `app-db-tx` precedent. Accepted equivalents (documented
in the evidence file): default-clock ±1ns arithmetic, `fetchOne(...limit 1→2)`
on single-row reads, the defensive `countPending()` non-scalar guard
(unreachable over SQL `COUNT(*)`), StoredEvent's own constructor
re-validation behind the store boundary, and savepoint round-trips in the
ambient-transaction paths.

##### Non-goals

- Projection rebuild tooling and checkpoint management stay out of scope —
  the global-sequence backstop makes future checkpoint consumers safe, but
  the projections themselves are a separate roadmap item.
- Upcasting is not a persistence-layer migration: stored bytes never change;
  transformation happens in memory during replay only.
- The outbox remains best-effort in-process; the relay's dead-letter
  requeue assumes a human (or orchestrator) fixed the root cause first.
