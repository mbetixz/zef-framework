# CHANGELOG v2.17.0 — Dokumentasi Resmi, Perbaikan Dispatcher CLI, dan Pembaruan Toolchain

Rilis ini bersifat **aditif**: tidak ada perubahan perilaku untuk rute, container,
atau API publik selain perbaikan dispatcher CLI yang dijelaskan di §2.

---

## 1. Dokumentasi resmi lengkap + publikasi GitHub Pages

Dokumentasi resmi kini lengkap dan diterbitkan otomatis.

| Berkas baru | Isi |
|-------------|-----|
| `docs/README.md` | indeks dokumentasi (tabel dokumen + rujukan cepat) |
| `docs/INSTALLATION.md` | persyaratan, jalur Composer & zero-composer, RoadRunner, Docker/K8s, **tabel variabel lingkungan**, verifikasi, masalah umum |
| `docs/CLI.md` | referensi lengkap `bin/zef`: `--self-test` (dengan semantik filter), `--serve`, tiga inspector, **10 generator `make:*`**, dan REPL `tinker` |
| `docs/QUALITY.md` | tujuh gerbang kualitas; **bab mutation testing**: definisi MSI/covered-MSI/mutation-coverage, daftar 27 zona, prosedur menjalankan satu zona (termasuk jebakan path relatif konfigurasi Infection), anggaran waktu, dan **triage mutan ekuivalen** |
| `docs/DEPLOYMENT.md` | topologi RoadRunner, titik pembekuan (*freeze points*), **matriks state lintas-request**, kepemilikan sinyal & graceful shutdown, kontrak probe K8s, observabilitas, keamanan runtime, runbook |

Perubahan pipeline:

- `scripts/build_docs.php` — perakit situs dokumentasi statis (Markdown → HTML).
  Memakai `parsedown/parsedown` mode aman yang **sudah** menjadi dependensi transitif
  `code-lts/doctum`, sehingga tidak ada perubahan pada `composer.json`/`composer.lock`.
  Menghasilkan navigasi berkelompok, daftar isi per halaman dengan *scroll-spy*,
  filter pencarian klien, dan menyalin Markdown asal ke `raw/` agar tetap dapat diunduh.
- `.github/workflows/pages.yml` — Pages kini menerbitkan **dokumentasi resmi** di akar
  situs, dengan API reference Doctum di-*merge* ke `build/docs/api`
  (sebelumnya Pages hanya menerbitkan `build/api`).
- `.github/workflows/docs-check.yml` — pemeriksaan read-only untuk PR ikut membangun
  situs dokumentasi dan **memverifikasi** enam halaman inti serta hasil merge API,
  sehingga PR yang merusak renderer atau navigasi gagal sebelum merge.
  Nama job `Build API documentation` dipertahankan persis karena bersifat
  *load-bearing* terhadap required status check pada branch protection `main`.

## 2. Perbaikan: `bin/zef tinker` tidak pernah terhubung ke dispatcher

**Defect.** `zef_tinker()` terdefinisi lengkap di `bin/zef` (termasuk guard produksi
`ZEF_ENV=production` dan mode `--no-boot`), tetapi **tidak pernah dipanggil** dari
rantai dispatch. Akibatnya:

```
$ php bin/zef tinker
Unknown command 'tinker'. Run 'bin/zef list' for all commands.   # exit 1
```

padahal `tinker` tercantum di `bin/zef list` — yaitu **janji CLI yang mati**.
Perbaikannya satu baris dispatch eksplisit sebelum cabang maker:

```php
if ($command === 'tinker') {
    exit(zef_tinker($args));
}
```

**Bukti perbaikan (sebelum → sesudah):**

| Probe | Sebelum | Sesudah |
|-------|---------|---------|
| `php bin/zef tinker -e 'echo 40 + 2;'` | `Unknown command 'tinker'`, exit 1 | `42`, exit 0 |
| `ZEF_ENV=production php bin/zef tinker -e 'echo 1;'` | `Unknown command`, exit 1 | menolak dengan pesan `--force`, exit 1 |
| `php bin/zef tinker -e … --force` (production) | — | exit 0 |

**Pagar regresi.** `tests/ZefCliDispatchTest.php` (baru, 7 test / 24 assertion)
menjalankan `bin/zef` sebagai subproses nyata dan mengunci kontrak dispatcher:
`list` mencantumkan generator terdokumentasi, identifier invalid pada `make:*` keluar
non-zero, `tinker -e` benar-benar mengevaluasi ekspresi, mode `--no-boot` bekerja,
guard produksi menolak tanpa `--force` dan patuh dengan `--force`, serta command tak
dikenal tetap ditolak.

## 3. RoadRunner

Biner RoadRunner diverifikasi pada **v2025.1.15** (versi yang juga dipakai
`spiral/roadrunner-http` ^4.1 di `composer.lock`), tersedia di `bin/rr` pada distribusi
arsip dan di `vendor/bin/rr`. Konfigurasi `.rr.yaml` tetap pada protokol `2025.1`.
Prosedur pemasangan biner (unggahan resmi atau `rr get-binary`) didokumentasikan di
`docs/INSTALLATION.md` §4.

## 4. Versi

`ZefVersion::VERSION` → **`2.17.0`**.

## 5. Status mutation testing (jujur, bukan klaim)

Rilis ini **tidak** mengklaim MSI 95% per area. Kampanye mutasi penuh dijalankan
ulang dari nol pada lingkungan bersih (PHP 8.4.25, Infection 0.30.3, PCOV) dan
hasilnya dilaporkan apa adanya, termasuk zona yang **belum** memenuhi target.
Target MSI ≥ 95% per area tetap menjadi pekerjaan terbuka dan memerlukan keputusan
anggaran dari owner (lihat laporan).

Gate CI yang berlaku pada rilis ini tetap **`--min-msi=85 --min-covered-msi=90`**
(tidak diubah), sesuai keputusan OD-50 yang terdokumentasi di `ci.yml`.
