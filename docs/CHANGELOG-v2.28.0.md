# ZEF Framework — CHANGELOG v2.28.0

**Env Static Facade Deprecation — penandaan akhir jalur migrasi `EnvInterface` (issue #55 step 4)**

> PHP 8.4 · zero new runtime dependencies · BC penuh: tidak ada API yang dihapus, diubah,
> atau dipindahkan pada rilis ini — satu-satunya perubahan perilaku adalah sinyal deprecasi.
>
> Rilis minor murni deprecation: seluruh 13 gate lokal hijau, PHPStan level max tanpa
> baseline baru, Deptrac 0 pelanggaran, sensus statis `Env::` di `src/` tetap **0**.

## Ringkasan

v2.28.0 menutup langkah terakhir (step 4) issue #55: **facade statis `Env`
kini resmi deprecated** dan dijadwalkan dihapus di **v3.0**. Seluruh pekerjaan
teknis migrasi sudah selesai pada tiga PR sebelumnya — observability (#83),
Domain (#84), Middleware + Adapters (#85) — sehingga sensus pemanggilan statis
di `src/` telah mencapai **0 dari 38 call site** sejak v2.27.x. Rilis ini
menandai permukaan lama itu sebagai *legacy* secara eksplisit, baik bagi
static analysis maupun runtime, tanpa memaksa siapa pun berpindah hari ini.

Dua mekanisme deprecasi aktif sekaligus (pola Symfony):

- **`@deprecated` pada docblock** seluruh metode statis publik
  (`Env::int/bool/string/csv`) — sinyal untuk IDE, PHPStan
  (via `phpstan-deprecation-rules` pada sisi konsumen), dan dokumentasi API
  yang dihasilkan Doctum.
- **`@trigger_error(..., E_USER_DEPRECATED)`** pada tiap pemanggilan statis —
  sinyal runtime yang ter-suppress (`@`) sehingga PHPUnit, self-test, dan
  log production tetap hening secara default, namun tetap tertangkap oleh
  error handler kustom yang secara eksplisit ingin memantau deprecasi
  (mekanisme ini yang dipakai guard test di bawah).

Tidak ada perubahan tanda tangan, badan fungsi, maupun binding kernel:
`EnvInterface` tetap terdaftar sebagai service `SINGLETON` di composition
root, dan permukaan instance `readInt/readBool/readString/readCsv` tetap
menjadi satu-satunya permukaan yang didukung untuk kode produksi baru.

## Tabel migrasi

| Facade statis (deprecated) | Pengganti via `EnvInterface` |
|---|---|
| `Env::int($name, $default, $min, $max, $strict)` | `$env->readInt(...)` — parameter identik |
| `Env::bool($name, $default)` | `$env->readBool(...)` — parameter identik |
| `Env::string($name, $default)` | `$env->readString(...)` — parameter identik |
| `Env::csv($name)` | `$env->readCsv(...)` — parameter identik |

Cara injeksi: tambahkan dependensi `EnvInterface` pada constructor (pola
*optional port parameter* `?EnvInterface $env = null` dipakai seluruh
modul selama migrasi — BC penuh untuk kode yang membangun objek sendiri),
atau ambil dari container di composition root. Semua parameter dan
semantik (clamping `$min/$max`, mode `$strict`, pemangkasan CSV) identik
—— permukaan instance mendelegasikan ke badan fungsi yang sama sejak PR #80.

## Timeline penghapusan

- **v2.28.0 (rilis ini)** — deprecasi aktif: tag `@deprecated` + notice
  runtime ter-suppress. Tidak ada perilaku yang berubah bagi pemanggil
  yang mengabaikan notice.
- **v2.x berikutnya** — jendela migrasi; facade statis tetap tersedia dan
  tetap mendelegasikan ke implementasi yang sama.
- **v3.0 (major)** — permukaan statis **dihapus**: `Env` menjadi kelas
  instance murni pengimplementasi `EnvInterface`. Seluruh konsumen
  diharapkan sudah berpindah ke injeksi port. Penghapusan akan disertai
  catatan upgrade (`UPGRADE-3.0.md`) dengan pemetaan mekanis satu-ke-satu
  persis seperti tabel di atas.

## Guard test baru

`tests/Unit/EnvStaticDeprecationTest.php` (4 test) mengawal deprecasi ini
agar tidak memudar diam-diam:

1. **Refleksi docblock** — keempat metode statis wajib membawa `@deprecated`
   (regenerasi file atau refactor yang menelan tag akan langsung merah).
2. **Notis runtime** — pemanggilan tiap metode statis memancarkan tepat
   satu `E_USER_DEPRECATED` yang menyebut `EnvInterface`, pengganti
   `readX()()`, versi deprecasi (v2.28.0), dan versi penghapusan (v3.0).
3. **Permukaan bersih** — `readInt/readBool/readString/readCsv` dijamin
   **tidak** memancarkan deprecasi apa pun (port adalah permukaan
   yang didukung, bukan ikut legacy).
4. **Konsistensi versi** — `ZefVersion::VERSION === '2.28.0'`, memastikan
   catatan rilis ini dan kode bergerak bersama.

## Detail teknis

- **`src/Domain/Foundation/Env.php`** — tag `@deprecated` + notice runtime
  pada 4 metode statis; header docblock diperbarui (sejarah 38→0 call site,
  mekanisme suppress, referensi changelog ini); docblock kelas menegaskan
  permukaan instance sebagai implementasi kanonik port.
- **`src/Domain/Foundation/ZefVersion.php`** — `2.27.0` → `2.28.0`
  (single source of truth versi framework).
- **`src/Adapters/Kernel/Application.php`** — komentar binding
  `EnvInterface` diperbarui: migrasi selesai, facade deprecated, jadwal
  removal v3.0.
- **`tests/Unit/EnvStaticDeprecationTest.php`** — guard test baru (file
  tambahan, 4 test).

## Gate

Seluruh 13 gate lokal hijau sebelum pengiriman PR: lint, self-test ×4
(default/v290/v210/v211), PHPUnit penuh (lihat angka di tabel PR), PHPStan
level max + strict rules tanpa tambahan baseline, ratchet baseline 509/509,
Deptrac 0 pelanggaran/0 warning/0 error, php-cs-fixer, phpcs, rector
dry-run bersih, dan mutation floor per zona `--floor=95` tetap terpenuhi.
