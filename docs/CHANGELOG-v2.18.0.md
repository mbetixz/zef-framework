# ZEF Framework v2.18.0 — Database Core

> Rilis expansi fitur pertama setelah kampanye mutasi v2.16.0/v2.17.0: **fondasi
> database** — query builder, adapter PDO, migrasi versi, dan repository base.
> Semuanya hexagonal, zero-composer-friendly, dan lolos gate mutasi zona baru
> **MSI 90% / Covered MSI 94%** (906/1000 mutan dibunuh, 4 ronde kurikulum).

## Ringkasan

Database Core mengisi lapisan infrastruktur terbesar yang belum ada di ZEF.
Port-nya murni di Domain, adapter PDO di Infrastructure, dan layanan migrasi +
repository di Application — mengikuti peta layer yang sama seperti Cache,
Security, dan Observability. Tidak ada dependensi baru; hanya `ext-pdo` yang
sudah tersedia di PHP 8.4.

## Yang baru

### 1. Query Builder (`Domain\Database\QueryBuilder`)
- Fluent, murni (tanpa koneksi): menghasilkan `SqlQuery{sql, params}` yang
  deterministik — builder state sama, SQL string sama.
- SELECT (kolom, `AS` alias, DISTINCT, INNER/LEFT/RIGHT/CROSS JOIN,
  GROUP BY, HAVING, ORDER BY, LIMIT/OFFSET), INSERT satu/multi-baris,
  UPDATE, DELETE.
- Kondisi: `where/orWhere/whereColumn/whereNested` (grup paren),
  `whereIn/whereNotIn` (list **atau** subquery — parameter subquery tetap
  terikat urut), `whereNull/whereNotNull/whereBetween/whereLike` (opsi escape
  wildcard + klausa `ESCAPE '\'`).
- Anti-injection ketat: setiap identifier divalidasi grammar
  `[A-Za-z_][A-Za-z0-9_]{0,63}`, double-quoted, maksimal satu titik
  (`table.column`); SQL dinamis hanya lewat `SqlExpression` eksplisit.
- Guard tabrakan bug: `UPDATE`/`DELETE` tanpa `WHERE` wajib memanggil
  `allowUnbounded()`; `where('col', '=', null)` ditolak (pakai
  `whereNull()`); `whereIn()` list kosong ditolak; nilai wajib
  null/bool/int/float/string/SqlExpression.
- Agregat: `count()/aggregate()` dengan whitelist COUNT/SUM/AVG/MIN/MAX →
  `SELECT COUNT(*) AS aggregate`.

### 2. Adapter PDO (`Infrastructure\Database\PdoConnection` + `ConnectionConfig`)
- Koneksi lazy — tidak ada socket sampai statement pertama.
- Transaksi nested dengan SAVEPOINT eksplisit (`zef_sp2`, `zef_sp3`, …),
  depth counter internal, batas nesting 16; `transaction(callable)` dengan
  rollback otomatis pada throwable apa pun.
- Isolation level (`IsolationLevel` enum) via `SET TRANSACTION ISOLATION
  LEVEL` hanya di level terluar; SQLite menolak dengan pesan tegas.
- Mapping error stabil: `\PDOException` → `ConnectionException` (fase
  koneksi) / `QueryException` (prepare/execute, pesan memuat SQL) /
  `TransactionException` (misuse) — driver tidak pernah bocor ke aplikasi.
- `ConnectionConfig::fromArray()` memvalidasi driver (mysql/pgsql/sqlite),
  host (tanpa spasi, ≤255 byte), port (1–65535), charset, opsi
  (`persistent`, `timeout`) — salah konfigurasi meledak saat konstruksi.
- DSN dibangun per-driver dan diuji exactness-nya.

### 3. Migrator (`Application\Database\Migrator` + `MigrationInterface`)
- Versi 14 digit (`YYYYmmddHHMMSS`), diterapkan ascending, dicatat di tabel
  `zef_migrations`; setiap migrasi berjalan dalam transaksinya sendiri
  (up + bookkeeping commit/rollback bersama).
- `plan()` tanpa efek, `migrate()`, `rollback(steps)` descending dengan
  guard jumlah langkah dan versi tidak-terdaftar.
- Lock satu-baris `zef_migrations_lock` dengan TTL + clock injectable:
  runner kedua ditolak dengan pesan `age Ns, ttl Ns`; lock stale
  (age ≥ TTL) direbut. Lock dilepas di `finally` bahkan saat gagal.
- up() yang melempar → transaksi rollback, catatan tidak tertulis, lock
  tetap lepas (diverifikasi tes).

### 4. Repository base (`Application\Database\Repository`)
- Gateway tipis di atas QueryBuilder: `insert/find/findOneBy/findBy/
  count/exists/update/delete` + `qb()` protected.
- Kriteria array `col => value` — `null` otomatis jadi `IS NULL`;
  `update()/delete()` tanpa kriteria ditolak di level repository.
- Sengaja BUKAN ORM: tanpa identity map/lazy relation/change tracking.

## Verifikasi

| Pintu | Hasil |
| --- | --- |
| php -l | 425 file, 0 gagal |
| `bin/zef --self-test` | 501/501 |
| PHPUnit | **1647 tes / 17.255 asersi** (5 skip kondisional) |
| PHPStan level max + strict | 0 |
| PHPCS (Slevomat) | 0 |
| PHP-CS-Fixer / Rector | 0 / 0 |
| Deptrac | 0 pelanggaran layer |
| Infection zona Database (1000 mutan) | **MSI 90% / Covered 94%** |
| Coverage zona baru | 94%+ (SQLite in-memory nyata, bukan mock) |

Kurikulum mutasi 4 ronde: ronde 1 baseline MSI 82%, ronde 2–4 menargetkan
klaster (assertSelect removals, normalisasi kasus, anchor regex, guard
scalar, bentuk pesan error regex-anchor, visibilitas lock selama up()/down(),
transisi level savepoint eksak, re-akuisisi lock, boundary TTL 299/300 s).
Sisa escape ditriase jujur di bagian bawah.

## Triase sisa escape (jujur-jujuran)

- **Pesan kegagalan driver-jauh** (`beginTransaction/commit/rollBack`
  wrapper + `runStatement`): butuh MySQL/PostgreSQL yang benar-benar
  gagal koneksi di tengah transaksi; SQLite tidak bisa memicunya
  (SAVEPOINT implisit selalu sukses).
- **Atribut PDO** (`persistent`, `timeout`, `EMULATE_PREPARES`,
  `STRINGIFY_FETCHES`): tidak teramati via SQLite in-memory.
- **Ekuivalen**: `array_values` pada list, `CastString` pada parameter
  bertipe string, `limit(1)→limit(2)` di fetchOne (ambil `[0]` sama),
  visibility mutator pada metode yang hanya dipanggil internal,
  `Throw_` pada guard baris-malformed yang tidak bisa diproduksi SQLite.

## Kompatibilitas

- Aditif penuh: tidak ada kelas/metode yang diubah; versi 2.17.0 → 2.18.0.
- Zero-composer autoloader diperbarui (classmap 607 kelas, 23 shim eager).
- Jalan setapak berikutnya: Event Sourcing + Outbox (EventStore butuh
  koneksi SQL), OpenAPI generator, adapter WebSocket/SSE.
