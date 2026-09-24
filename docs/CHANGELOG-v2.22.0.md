# ZEF Framework — CHANGELOG v2.22.0

**Transaction orchestration & UoW-lite** — lapisan orkestrasi transaksi di
atas primitif koneksi v2.18.0: scope transaksi terkelola dengan hook
after-commit, decorator command bus transaksional, dan antrean tulisan
deferred yang flush atomik sebelum commit.

## Ringkasan

- **`TransactionManagerInterface` (port, `src/Domain/Database/`)** —
  kontrak orkestrasi di atas primitif `ConnectionInterface`:
  - `withTransaction($fn, ?IsolationLevel)` — scope terkelola; nested call
    menjadi savepoint di connection, commit terjadi di scope terluar;
  - `afterCommit($hook)` — antrean hook FIFO yang baru berjalan SETELAH
    commit terluar: gagal rollback = hook dibuang (command yang gagal
    tidak pernah memancarkan event/cache-invalidation), di luar scope
    hook berjalan langsung (pemanggil tidak perlu tahu ada transaksi
    atau tidak), hook yang mendaftar hook saat drain berjalan inline;
  - `inTransaction()` / `level()` — status scope terkelola.
  Kontrak lengkap ada di docblock port; implementasi wajib menghormati
  semantik FIFO, discard-on-rollback, dan propagate-after-commit.

- **`TransactionManager` (default impl, `src/Application/Database/`)** —
  implementasi default di atas satu `ConnectionInterface`. Mekanika
  BEGIN/SAVEPOINT/isolation tetap milik koneksi; kelas ini hanya
  memegang bookkeeping scope (`$scopeDepth`) dan siklus hidup hook
  (`$hooks` + `$flushing`): antrean dibersihkan SEBELUM drain (hook
  gagal tidak pernah terulang), flag `$flushing` mengaktifkan mode
  inline, dan kegagalan drain tidak meninggalkan residu.

- **`TransactionalCommandBus` (decorator, `src/Application/CQRS/`)** —
  `final readonly class` yang membungkus `CommandBusInterface`:
  setiap `dispatch()` berjalan di dalam `withTransaction`, `UnitOfWork`
  opsional di-flush ke koneksi transaksi SEBELUM commit (gagal flush =
  rollback seluruh command), dan isolation level opsional dapat dipaksa
  di scope terluar. Nested dispatch melalui decorator yang sama menjadi
  savepoint — command terluar pemilik commit.

- **`CommandBus` (perubahan kecil, backward-compatible)** — parameter
  constructor baru opsional `?TransactionManagerInterface $transactions`:
  ketika terpasang, fan-out event hasil handler (`CqrsEventResult`)
  dirutekan melalui `afterCommit()` — event hanya terlepas setelah
  commit terluar dan hilang saat rollback; tanpa scope terkelola,
  `afterCommit()` berjalan langsung sehingga timing pra-2.22 dipertahankan
  persis. Tanpa parameter, perilaku tidak berubah sama sekali.

- **`UnitOfWork` (UoW-lite, `src/Application/Database/`)** — bukan ORM:
  framework tidak punya entitas untuk dilacak. Yang dibutuhkan handler
  CQRS adalah antrean tulisan deferred dengan jaminan transaksional:
  - `record($op)` / `recordQuery($sql)` — antrean FIFO;
  - `flush($connection)` — eksekusi berurutan di koneksi transaksi,
    antrean dibersihkan SEBELUM eksekusi (gagal tidak pernah terulang
    dari antrean yang sama; command yang di-retry merekam ulang);
  - `pending()` / `discard()` — observasi dan pembatalan eksplisit;
  - re-entry `flush()` dan `record()` saat flushing ditolak lantang
    (`TransactionException`) — urutan ambigu adalah bug pemanggil.

## Motivasi

Primitif v2.18.0 (`beginTransaction/commit/rollBack/transaction()`)
benar dan lengkap untuk SATU scope, tetapi tiga kebutuhan orkestrasi
tidak terjawab: (1) event fan-out CQRS berjalan SEBELUM commit —
listener membaca data yang belum ter-commit dan command yang rollback
tetap memancarkan event; (2) tidak ada tempat idiomatik untuk
"jalankan ini setelah commit"; (3) handler yang menulis dari beberapa
titik tidak punya jaminan atomik kolektif. v2.22.0 menjawab ketiganya
tanpa menyentuh semantik primitif.

## Non-tujuan (disengaja)

- **Distributed/Two-phase commit** — manager mengelola SATU koneksi;
  koordinasi lintas-koneksi menunggu kebutuhan nyata.
- **Entity tracking / identity map** — tanpa mapper layer, UoW berbasis
  entitas akan menambahkan abstraksi tanpa pemakai; UoW-lite cukup.
- **Outbox pattern** — antrean event persisten terpisah dari transaksi;
  hook after-commit adalah kontrak in-process, bukan pengganti outbox.

## Mutasi

Zona baru `app-db-tx` (4 file: port + TransactionManager + UnitOfWork +
TransactionalCommandBus): **MSI 95.92% / covered 95.92%** dari 49 mutan
(47 terbunuh; 2 escaped adalah early-return antrean kosong tanpa delta
observable — false positive). Baris zona + baseline + evidence tercatat
di `docs/mutation/`; floor beku 95.92.

## Kualitas

- PHPUnit: 2268 tes / 19333 asersi (5 skip — pre-existing) — 32 metode
  uji baru (TransactionManager 12, UnitOfWork 11, TransactionalCommandBus
  integrasi SQLite 9);
- PHPStan level max: 0 error; baseline ratchet tidak berubah;
- CS-Fixer / PHPCS / Rector: bersih; Deptrac: 0 pelanggaran;
- lint 578 file: 0 gagal; self-test CLI: 501/501;
- SAST (CodeQL, Semgrep, gitleaks): bersih.
