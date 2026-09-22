# ZEF Framework — CHANGELOG v2.16.0

**ZEF Maker: scaffold anything from the CLI.** Melanjutkan mandat DX v2.8.0/v2.10.0,
seluruh artefak kerja framework kini bisa dibuat dari `bin/zef` — plugin, config
provider, CQRS command/query, entity, value object, service — ditambah tiga inspector
dan katalog command terpusat. Logika generator pindah dari procedural `bin/zef` ke
layer Infrastructure yang berada **di dalam gate mutasi 85/90**, sehingga kualitas CLI
dijamin setara inti framework.

## Added

- **7 generator baru** (`bin/zef make:*`):
  - `make:plugin <Name>` — scaffold `plugins/<Name>/` lengkap: `ConfigProvider`
    (service singleton + handler dengan injeksi dependensi + route), `<Name>Service`,
    `<Name>Handler` (mengikuti pola plugin Toko).
  - `make:config <Name> [--module=]` — scaffold **ConfigProvider** di dalam modul;
    ini mekanisme config nyata ZEF (config diagregasi dari provider, bukan file
    lepas), lengkap dengan contoh akses dotted `$aggregator->get('<module>.<key>')`.
  - `make:command <Name> [--module=]` — pasangan CQRS `Command/<Name>Command.php`
    (immutable intent, `public readonly`) + `<Name>CommandHandler.php`
    (`CommandHandlerInterface`, `__invoke(object, CqrsContext): mixed`) + snippet
    wiring `register()` dan `getCommandBus()->dispatch()`.
  - `make:query <Name> [--module=]` — pasangan CQRS read-side `Query/`
    (`QueryHandlerInterface`) + snippet `getQueryBus()->ask()`.
  - `make:entity <Name> [--module=]` — entitas Domain `Domain/<Name>.php` dengan
    identity semantics: `equals()` berbasis id, `createdAt` immutable.
  - `make:valueobject <Name> [--module=]` — `final readonly class` dengan validasi
    constructor (invalid instance unrepresentable), `equals()`, `__toString()`.
  - `make:service <Name> [--module=]` — service aplikasi + wiring snippet; nama
    berakhiran `Service` tidak didobel-suffix.
- **3 inspector** (composition root tetap di `bin/zef` — boot aplikasi tidak pernah
  dilakukan dari layer Infrastructure):
  - `bin/zef module:list` — modul terdaftar nyata dari `ModuleRegistry` pasca-boot
    (nama + class konkret + count).
  - `bin/zef plugin:list` — scan `plugins/` on disk (sumber kebenaran untuk plugin,
    karena runtime tidak men-tag plugin); entri non-dir diabaikan.
  - `bin/zef config:show [key]` — dump config teragregasi; **JSON-safe**: Closure
    (factory) → `"<closure>"`, object → `"<object Class>"`, resource →
    `"<resource>"`; lookup dotted key dengan sentinel yang membedakan *missing*
    (exit 1) dari *null tersimpan* (exit 0).
- **`bin/zef list [--json]`** — katalog command terpusat (10 generator), sumber
  kebenaran tunggal untuk output list dan pesan error generator tak dikenal.
- **Infrastruktur Console** (`src/Infrastructure/Console/`, 22 class, semuanya
  terdaftar di classmap zero-composer):
  - `ZefMaker` — katalog + dispatcher (`GeneratorInterface` typed, tanpa object/mixed).
  - `NamingRules` — validasi class name (termasuk **penolakan reserved word PHP**
    case-insensitive — `make:entity List` kini ditolak, bukan menghasilkan kode
    rusak) + module name `[a-z][a-z0-9_-]{0,31}` + konversi Pascal/snake/kebab.
  - `ScaffoldWriter` — penulis **transaksional**: seluruh target dicek collision
    dulu, tulis belakangan; satu tabrakan membatalkan batch tanpa file parsial.
  - `ConsoleIO` — port output dengan log in-memory (test tanpa subprocess).
  - `InvalidNameException` / `ScaffoldCollisionException` / `ScaffoldWriteException`
    (marker `ConsoleException`) → dispatcher mengubahnya menjadi exit 1 + pesan stderr.
- Kurikulum edge test: `EdgeMatrixMakerTest` (katalog, dispatcher, writer
  transaksional, byte-stream IO, inspector) + `EdgeMatrixMakerGeneratorsTest`
  (10 generator, assert sampai level token: namespace, service id, kontrak
  interface, guidance next-steps).

## Changed

- **`bin/zef` dirombak menjadi thin composition root** (472 → ~230 baris): dispatch
  `--self-test`, `--serve`, `route:list`, `tinker` tidak berubah; seluruh logika
  generator lama (`zef_make_*`, helper nama, `zef_write_file`) dihapus dan diganti
  delegasi ke `ZefMaker`.
- Command tidak dikenal kini **exit 1** dengan arahan `bin/zef list` (sebelumnya:
  mencetak banner + exit 0 — kontras dengan konvensi CLI).
- Next-steps `make:module` kini merujuk `composer dump-autoload` (teks lama
  merujuk `scripts/update_classmap.php` yang tidak pernah ada).

## Fixed

- **`make:middleware` kini scaffold ke `src/Middleware/`** — target PSR-4 composer
  untuk namespace `Zef\Middleware\`. Target lama `app/Middleware/` tidak dipetakan
  oleh autoloader, sehingga file hasil scaffold tidak bisa di-autoload (warisan
  layout monolith v2.7.0 yang terpisah saat hexagonal split).

## Quality (level max, sesuai standar kampanye)

- PHPUnit: **1484+ test / 16.7k+ asersi hijau** (66 test Maker baru; 5 skip
  terdokumentasi).
- PHPStan max+strict: 0 error; PHPCS Slevomat: 0; php-cs-fixer: 0; Rector: 0;
  deptrac: 0 pelanggaran (Console berada di layer Infrastructure, inspectors
  menerima `ModuleRegistry`/`ConfigAggregator` via injeksi — tanpa dependensi naik-layer);
  lint 417 file: 0.
- **Mutasi namespace Console: 387/387 mutan mati — MSI 100% / Covered MSI 100%**
  (chunk `f16-maker-v4`, gate chunk dinaikkan ke 90). Triage jujur 3 mutan
  ekuivalen dengan `@infection-ignore-all` berjustifikasi: coalesce null-render
  di pesan NamingRules, `(string)` kunci array di `jsonSafe` (normalisasi kunci
  PHP), LogicalAnd guard mkdir race-safe.
- **Gate global 85/90 TERTAHAN via komposisi eksak**: total **9.419 mutan**
  (9.032 baseline v2.15.0 — file unchanged, diverifikasi via `git status -- src/`
  yang hanya menunjukkan `Console/` baru — + 387 Console segar) →
  **MSI 90.77% / Covered MSI 93.38%** (margin +5.77 / +3.38).
  Cross-validation segar 16 zona / 5.902 mutan (seluruh Domain + Application +
  Infrastructure + Adapters/Http): MSI 92.32% — konsisten, tanpa degradasi.
  Zona berat kernel (Application.php, Dispatcher) diverifikasi via bukti git
  (unchanged) + full-run v2.15.0; eksekusi ulang terhalang limit CPU foreground
  sandbox (proses detached ber-CPU-berat dibunuh ~t+3..5m, terdokumentasi).
- coverage-gate: **94.49%** PASSED (naik dari 94.06%); bench tanpa regresi
  (1.124µs singleton / 3.885µs transient).

## Compatibility

- Tidak ada perubahan pada kontrak runtime, container, router, CQRS, atau middleware.
- `bin/zef make:module/handler/middleware` mempertahankan perilaku lama (nama file,
  service id, teks guidance) dengan dua pengecualian yang didokumentasikan di atas
  (lokasi middleware, referensi classmap).
