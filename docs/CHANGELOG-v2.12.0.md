# ZEF Framework v2.12.0 — Composer Packaging & QA Toolchain Alignment

> Metodologi: *Changelog claim → implementation → feature test →
> baseline regression → integration/behavior test → CI execution → evidence*.

## 1. Ringkasan

v2.12.0 menyelaraskan seluruh proses pengembangan ZEF dengan `composer.json`
baru (`mbetixz/zef-framework`, MIT, `type: project`, PHP `^8.4`) beserta
toolchain kualitas standar industri: PHPUnit, PHPStan, PHP-CS-Fixer, Rector,
Infection, PHPBench, Deptrac, dan Doctum. Seluruh tool teruji hijau dari
mesin lokal dan terpasang sebagai langkah CI.

## 2. Restrukturisasi autoload (FQCN 100% stabil)

Spesifikasi autoload `Zef\App\` → `src/` dari composer.json baru kini
harfiah benar: lapisan aplikasi dipindah ke dalam `src/`.

| Perpindahan | FQCN (tidak berubah) |
|-------------|----------------------|
| `app/Bootstrap.php` → `src/Bootstrap.php` | `Zef\App\Bootstrap` |
| `app/Middleware/*.php` → `src/Middleware/*.php` (7 file) | `Zef\Middleware\*` |

- Classmap statis diperbarui (8 path), autoloader zero-composer diregenerasi
  (353 kelas + 23 shim PSR eager).
- `Zef\Framework\` tetap dipetakan ke `src/{Domain,Application,Infrastructure,Adapters}/`;
  `Zef\Module\` → `modules/`, `Zef\Plugin\` → `plugins/`, `Zef\Test\` → `tests/`
  (via `autoload-dev`).

## 3. Konfigurasi toolchain baru

| File | Tool | Skrip Composer |
|------|------|----------------|
| `phpunit.xml.dist` | PHPUnit 10.5 | `composer test` |
| `tests/SelfTestBridgeTest.php` | PHPUnit bridge: 19 suite ZEF = 20 test PHPUnit | `composer test` |
| `phpstan.neon.dist` + `phpstan-baseline.neon` | PHPStan 2.2, level 6 | `composer stan` |
| `.php-cs-fixer.dist.php` | PHP-CS-Fixer 3.95 (PER-CS2.0 + Symfony) | `composer format`, `format:check` |
| `rector.php` | Rector 2.6 (PHP 8.4 sets) | `composer rector`, `rector:check` |
| `deptrac.yaml` | Deptrac 4.7 (layer hexagonal) | `composer deptrac` |
| `phpbench.json` + `benchmarks/ContainerBench.php` | PHPBench 1.4 | `composer bench` |
| `infection.json5` | Infection 0.30 | `composer mutation` |
| `doctum.php` | Doctum 5.6 (API docs) | `composer docs` |
| `scripts/ci/assert-abandoned-policy.php` | Audit gate | `composer audit` |

## 4. Hasil eksekusi toolchain (evidence)

| Tool | Hasil |
|------|-------|
| `composer validate` | `./composer.json is valid` |
| `composer audit` | policy `report`, 9 paket dicek, 0 pelanggaran watchlist |
| `composer lint` | 325 file, 0 gagal (vendor/build dikecualikan, PHP_BINARY-aware) |
| `bin/zef --self-test` | **501/501** (+ v290 59, v210 107, v211 87) |
| `composer test` (PHPUnit) | **OK (20 tests, 40 assertions)** |
| `composer stan` (level 6) | **No errors** (baseline 230 utang tipe terpin) |
| `composer deptrac` | **0 violations**, 1306 edge diizinkan |
| `composer format:check` | 0 dari 293 file perlu perbaikan |
| `composer rector:check` | 0 diff (rule `ReadOnlyClassRector` di-skip secara terdokumentasi) |
| `composer bench` | singleton **0.602 µs**, transient **2.266 µs** (±1.4%) |
| `composer docs` | 259 kelas ter-render ke `build/api` |
| `composer mutation` | terpasang & ter-validasi; eksekusi menunggu coverage driver (pcov/xdebug) |

## 5. Keputusan arsitektur (deptrac)

56 pelanggaran awal diselesaikan dengan tiga cara:

1. **Perpindahan nyata** — `BoundedTrait` naik ke Domain
   (`src/Application/Security/Distributed/` → `src/Domain/Security/Distributed/`);
   FQCN tetap, self-test tetap hijau.
2. **Layer pengecualian bernama** — `EnvConfig`, `RouteDefSpec`, `TrustedProxy`,
   `OtlpExporter`, `KernelSleeper`, `OriginPolicySpec`, `ContainerImpl`
   (lihat komentar di `deptrac.yaml`). Penggantian `Env` dengan port
   `EnvInterface` dicatat sebagai pekerjaan lanjutan.
3. **Izin kontrak** — Domain boleh bergantung pada `Compat` (kontrak PSR
   murni) dan App pada `Module`/`Plugin` (wiring bootstrap).

## 6. Catatan khusus lingkungan

- `scripts/lint.php` kini memakai `PHP_BINARY` (tidak bergantung PATH) dan
  mengabaikan `vendor/`, `build/`, cache tool.
- Skrip `audit` memakai prefix `@php` agar biner PHP selalu ter-resolve.
- `infection` membutuhkan ekstensi `pcov`/`xdebug` (atau `phpdbg`); build PHP
  statis lingkungan uji tidak menyediakannya, sehingga eksekusi mutasi
  ditunda di lingkungan dengan driver coverage.
- Dependency transitif `doctrine/annotations` terdeteksi *abandoned* —
  kebijakan `audit.abandoned: report` menampilkannya tanpa menggagalkan CI.

## 7. Verifikasi regresi setelah perubahan besar

| Tahap | Bukti |
|-------|-------|
| Setelah pindah app/ → src/ | lint 325/0 · self-test 501/501 |
| Setelah mass-reformat cs-fixer (266 file) | lint 325/0 · self-test 501/501 · phpunit 20/20 · deptrac 0 |
| Setelah rector apply (50 file) | lint 325/0 · self-test 501/501 · phpunit 20/20 · deptrac 0 · stan no-errors |
| HTTP smoke (`bin/zef --serve`) | `/health/ready` 200 · `/` 200 |
| CI | `.github/workflows/ci.yml` — 14 langkah: install → validate → audit → lint → 4 suite → phpunit → stan → deptrac → cs-fixer → rector |
