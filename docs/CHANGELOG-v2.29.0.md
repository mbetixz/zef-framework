# ZEF Framework — CHANGELOG v2.29.0

**DX Release — Standalone Apps, RoadRunner Transparency, Zero-to-Hero Docs**

> PHP 8.4 · zero new runtime dependencies · seluruh 13 gate lokal hijau ·
> PHPStan level max tanpa baseline baru · Deptrac 0 pelanggaran · CLI baru
> dan seluruh alur tutorial diverifikasi end-to-end (scaffold → boot →
> CQRS dispatch → HTTP 200).

## Ringkasan

v2.29.0 adalah **rilis Developer Experience**: tidak ada perubahan kernel,
tidak ada API yang berubah — yang berubah adalah **seberapa cepat developer
dari `git clone` sampai aplikasi CQRS yang melayani trafik di RoadRunner**.
Empat pengiriman utama:

1. **`bin/zef make:app <path>`** — scaffold aplikasi standalone lengkap
   (pengganti in-repo untuk paket `composer create-project` terpisah).
2. **`bin/zef rr:init` + `bin/zef doctor`** — RoadRunner transparan:
   konfigurasi worker pool dihasilkan dari knob `ZEF_*`, dan kesehatan
   lingkungan terverifikasi sebelum developer membuang waktu men-debug hang.
3. **`docs/TUTORIAL-CQRS-101.md`** — tutorial Zero-to-Hero CQRS yang seluruh
   langkahnya dieksekusi dan diverifikasi saat rilis disiapkan.
4. **`docs/PLUGINS.md`** — kontrak manifest plugin + kriteria registry index,
   dengan `plugins/Toko` sebagai referensi implementasi.

## 1. `bin/zef make:app` — scaffold aplikasi standalone

Satu command menghasilkan proyek mandiri di luar checkout framework:
`composer.json` (path-repo balik ke framework), `.env.example`, `.rr.yaml`,
`app/Bootstrap.php` (composition root), modul pertama (`modules/<Nama>/`),
`public/index.php`, `bin/worker.php`, `bin/zef` (wrapper ZEF Maker), dan
README proyek.

Detail keselamatan dan kebenaran path (diverifikasi lewat kurikulum test
adversarial):

- **Normalisasi leksikal** — target relative mengandung `..` (mis.
  `../demo`) dinormalisasi sebelum containment check, sehingga target yang
  benar-benar di luar root tidak salah ditolak dan target "menyamar"
  (`sub/../modules/x`) tetap ditolak.
- **Containment guard** — scaffold di dalam framework root ditolak eksplisit
  (layout path-repo rusak bila nested).
- **Path-repo dari leluhur bersama** — URL `repositories[].url` dihitung
  dari *longest common ancestor* target vs framework root, bukan sekadar
  `basename`; checkout bersarang kapan pun tetap resolve. (Regressi test:
  framework di `<ws>/vendor/lib/zef`, target `<ws>/apps/demo` wajib
  menghasilkan `../../vendor/lib/zef`.)
- **Konsistensi namespace** — skeleton memetakan `Zef\Module\` → `modules/`
  dan `Zef\Plugin\` → `plugins/`, dan modul pertama memakai namespace yang
  sama dengan keluaran `make:module`, sehingga seluruh generator `make:*`
  bekerja langsung di dalam proyek hasil scaffold.

## 2. `bin/zef rr:init` — RoadRunner transparency

Menghasilkan `.rr.yaml` (RR v2025.1: `server.command`, pool HTTP, supervisor
memori) dari precedensi **flag CLI > env knob > default**:

| Knob | Flag | Env | Default |
|---|---|---|---|
| Address | `--address=host:port` (IPv4/IPv6) | `ZEF_HTTP_ADDRESS` | `0.0.0.0:8080` |
| Workers | `--workers=1..1024` | `ZEF_RR_NUM_WORKERS` | `4` |
| Max jobs | `--max-jobs=N` (0 = unbounded) | `ZEF_WORKER_MAX_JOBS` | `0` |
| Memory/worker | `--memory=MB` (0 = off) | `ZEF_WORKER_MEMORY_LIMIT` | `512` |

**Collision-safe**: tanpa `--force`, berkas eksisting tidak pernah ditimpa
(`ScaffoldWriter` menolak overwrite; pesan mengarahkan ke `--force`).
Untuk mendukung alur regenerasi yang sah, `ScaffoldWriter` menambah method
`overwriteFile()` — single-file, dipakai hanya oleh jalur `--force`; kontrak
all-or-nothing `writeFiles()` untuk scaffold lain tidak berubah.

## 3. `bin/zef doctor` — environment preflight

Preflight **read-only** dengan format `[OK|WARN|FAIL] label detail`:

- **PHP & ekstensi**: PHP ≥ 8.4 (FAIL bila di bawah), `mbstring` (FAIL),
  `posix`, `pcov`, `redis` (WARN bila absen, dengan implikasi dijelaskan).
- **Filesystem**: autoloader (`vendor/autoload.php` atau
  `autoload/zef_autoload.php` — FAIL bila tak ada), entrypoint
  `bin/worker.php` + `public/index.php` (WARN).
- **RoadRunner**: bridge `spiral/roadrunner-http` (class probe — `bin/zef`
  kini me-load `vendor/autoload.php` hanya untuk jalur doctor agar hasilnya
  akurat), binary `rr` (PATH + `vendor/bin` + `bin/`), validitas `.rr.yaml`
  (section `version:` + `server:`; bila malformed → WARN yang mengarahkan ke
  `rr:init --force`).
- **Boot smoke**: closure boot di-inject dari composition root `bin/zef`
  (Infrastructure tetap tidak bergantung pada App — Deptrac bersih); pada
  sandbox tanpa autoloader ia ter-skip terdokumentasi, bukan error.

**Kontrak exit code**: `1` hanya ketika ada minimal satu FAIL — WARN boleh
ditindaklanjuti tanpa memecah script CI.

## 4. Dokumentasi Zero-to-Hero + kontrak plugin

- **`docs/TUTORIAL-CQRS-101.md`** — 9 bagian dari `make:app` sampai dispatch
  CQRS lewat HTTP dan RoadRunner, termasuk **troubleshooting tiga jebakan
  klasik** yang seluruhnya ditemukan dan diperbaiki saat persiapan rilis ini:
  wiring bus sebelum boot (`ServiceNotFoundException`), registrasi setelah
  freeze (`CommandBus is frozen`), dan referensi handler antar sub-namespace
  tanpa `use`.
- **`docs/PLUGINS.md`** — kontrak manifest plugin (layout, namespace
  `Zef\Plugin\<Nama>`, service id, `getConfig()`, hook lifecycle opsional
  via `AbstractModule`), aturan registrasi composition root, kriteria
  registry index, dan panduan distribusi (in-repo & composer, tanpa
  auto-discovery runtime).

## 5. Perbaikan hint generator (BC-safe)

Petunjuk keluaran `make:command` / `make:query` diperbaiki dua hal:

- **Baris `use` ditambahkan** ke snippet wiring — handler tinggal di
  sub-namespace `Command\` / `Query\`; tanpa import, factory closure di
  `ConfigProvider` me-resolve kelas ke namespace yang salah
  (`Class "…PlaceOrderCommandHandler" not found`).
- **Contoh registrasi bus dikoreksi** — `register(string $messageClass,
  callable|CommandHandlerInterface $handler)` tidak menerima *string service
  id*; contoh kini memakai `$container->get('modul.command.nama')` dan
  mengarahkan ke `register()` hook modul.

Teks hint bukan API, namun diikat test — `EdgeMatrixMakerGeneratorsTest`
tetap hijau dengan konten baru.

## 6. Sensus dampak

| Area | v2.28.0 | v2.29.0 |
|---|---|---|
| Command maker (`bin/zef list`) | 10 | **11** (`make:app`) |
| Command CLI non-maker | `tinker`, `route:list`, `module:list`, `plugin:list`, `config:show`, `openapi:generate`, `--serve`, `--self-test` | **+ `rr:init`**, **+ `doctor`** |
| Dokumen developer | ARCHITECTURE, CLI, DEPLOYMENT, INSTALLATION, QUALITY, GOVERNANCE, ROADMAP, WIKI-INDEX | **+ TUTORIAL-CQRS-101**, **+ PLUGINS** |
| Dependensi runtime | 3 paket | **3 paket (tetap)** |

## 7. Kualitas

- Test baru: `EdgeMatrixDxToolsTest` (23 test, 222 assertion — kurikulum
  adversarial untuk `AppGenerator`, `RoadRunnerConfigGenerator`, `Doctor`)
  dan 3 test dispatch subprocess (`ZefCliDispatchTest`: doctor exit-0,
  rr:init collision-safe, make:app usage error).
- `EdgeMatrixMakerTest` diperbarui mengikuti katalog 11 command (nama test,
  struktur `list`, pesan *Supported:*).
- Classmap zero-composer (`autoload/zef_autoload.php`) diperbarui untuk tiga
  class baru (`AppGenerator`, `RoadRunnerConfigGenerator`, `Doctor`) — entry
  palsu hasil pemindaian heredoc dibersihkan manual.
- Versi: `ZefVersion::VERSION = '2.29.0'`; skeleton scaffold mengunci
  `mbetixz/zef-framework: ^2.29.0`.
