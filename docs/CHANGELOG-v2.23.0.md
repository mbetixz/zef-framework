# ZEF Framework — CHANGELOG v2.23.0

**Config v2 future enhancements (issue #60)** — keempat item roadmap dari
review v2.21.1 kini terimplementasi penuh: observability pipeline secrets,
cache index radix OPcache-friendly, memoization pattern query, dan jalur
migrasi versi skema. Semua additive dan opt-in; boot default tidak berubah
perilaku sama sekali.

## Ringkasan

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

## Motivasi

Issue #60 melacak empat peningkatan yang diidentifikasi saat review v2.21.1.
P1 menutup blind spot produksi: retry/fallback secrets yang tak teramati
berarti insiden "config boot lambat/korup" tidak punya sinyal. P2 melanjutkan
filosofi compiled-boot (`ConfigCompiler` menghapus pipeline sumber;
`RadixTreeCache` menghapus rebuild index). P3 meng-cover hot path yang
mengiterasi pattern sama per request. P4 menyiapkan jalur evolusi skema
sebelum dibutuhkan — mekanisme teruji, tanpa hop terdaftar selama semua
skema masih versi 1.

## Kompatibilitas

- Semua API baru additive dan opt-in; signature lama tidak berubah.
- Boot default: tanpa metrics binding, tanpa cache radix, tanpa migrator —
  perilaku byte-identical dengan v2.22.0 (48 test baru + 2274 test lama
  hijau, PHPStan level maksimum bersih, deptrac 0 pelanggaran).
- File cache radix menyimpan secrets resolved — sensitivitas sama dengan
  `config.php` terkompilasi (chmod 0600, `var/cache/` di `.gitignore`).

## Testing

Empat file test baru (`tests/Unit/ConfigV2{SecretsMetrics,RadixCache,
PatternQueryCache,SchemaVersioning}Test.php`, 48 test / 95 assertion):
kosakata metric + bounding label + "nilai secret tidak pernah jadi label",
round-trip fidelity index cache + invalidasi fingerprint/versi + miss lunak
korupsi + izin file 0600 + residu temp nol, hit/miss memo + anti-poisoning
CoW + invalidasi struktural, dan tangga migrasi multi-hop + penolakan
downgrade + integrasi loader (migrasi sebelum resolusi secrets).

Menutup issue #60 (P1–P4).
