# ZEF Framework v2.14.6 — Paket lengkap: bridge RoadRunner + binary `rr` + vendor penuh

Rilis ini tidak menyentuh kode framework — fokusnya adalah **kelengkapan paket**.
Temuan dari distribusi sebelumnya: `vendor/bin` hanya memuat 16 stub proxy Composer
(proxy 3 KB), binary RoadRunner tidak pernah disertakan, dan bridge PHP worker harus
dipasang manual. Semua celah itu ditutup.

## Yang berubah

### 1. Bridge RoadRunner kini dependency resmi (composer.json `require`)

`bin/worker.php` sejak v2.7.0 memakai pola *zero-composer fallback* — bridge
di-`class_exists` guard dan framework inti tetap bebas dependency. Kini bridge
disertakan dalam paket sehingga worker siap jalan tanpa langkah manual:

| Paket | Versi | Peran |
|---|---|---|
| `spiral/roadrunner-http` | v4.1.0 | HTTP worker bridge (PSR-7 ↔ RR) |
| `spiral/roadrunner-worker` | v3.6.2 | protokol worker (payload, worker lifecycle) |
| `spiral/roadrunner` | v2025.1.15 | PHP SDK (INFrastrukture client) |
| `spiral/goridge` | 4.2.2 | relay pipe RPC |
| `roadrunner-php/roadrunner-api-dto` | v1.17.0 | DTO protobuf internal |
| `nyholm/psr7` | 1.8.2 | implementasi PSR-7/PSR-17 |
| `psr/http-message` + `psr/http-factory` | 2.0 / 1.1.0 | interface standar |
| `google/protobuf` | v5.36.2 | runtime protobuf untuk DTO |

Fallback tidak hilang: hapus `vendor/` dan `bin/worker.php` masih keluar dengan
pesan ramah yang menuntun pemasangan manual.

### 2. Binary RoadRunner `rr` v2025.1.15 di `vendor/bin/rr`

Binary Go resmi (linux-amd64, go1.26.4, build 2026-06-17) disertakan lengkap dengan
tanda tangan `rr.asc` di `vendor/bin/`. Versinya **persis sama** dengan PHP SDK
`spiral/roadrunner` v2025.1.15 yang baru dipasang, sehingga protokol worker dan
client saling cocok tanpa jebakan versi.

```bash
vendor/bin/rr --version
# rr version 2025.1.15 (build time: 2026-06-17..., go1.26.4), OS: linux, arch: amd64
```

### 3. `.rr.yaml` siap-serve di root project

Konfigurasi minimal (`server.command: php bin/worker.php`, `http.address
0.0.0.0:8080`, pool 4 worker dengan `max_worker_memory` 512 MB, log development)
— `vendor/bin/rr serve` langsung hidup tanpa penulisan config.

### 4. Perbaikan `bin/worker.php` — dua akar bug berusia sejak v2.7.0

Smoke-test end-to-end (`rr serve` + curl) menyingkap dua lapis masalah pada
entrypoint worker yang sebelumnya tidak pernah bisa diuji riil karena binary
`rr` tidak ada:

1. **Composer autoload tidak dimuat.** Worker hanya me-require
   `autoload/zef_autoload.php` (loader internal) sehingga bridge di vendor
   tidak pernah ter-load — `WorkerAllocate: EOF` dari `rr serve`. Kini worker
   memuat `vendor/autoload.php` bila ada (sudah mencakup framework via
   PSR-4 + `files`) dan jatuh kembali ke `autoload/zef_autoload.php` bila
   `vendor/` dihapus — fallback zero-composer tidak berubah perilaku.
2. **API bridge yang salah.** Worker memakai `HttpWorker` gaya bridge v2 yang
   mengembalikan PSR-7; pada bridge v4.1 `HttpWorker::waitRequest()` kini
   mengembalikan DTO `Spiral\RoadRunner\Http\Request` (immutable, non-PSR-7),
   sehingga guard `!$request instanceof ServerRequestInterface` di
   `RoadRunnerRuntime` langsung `break` → exit 0 senyap per request (diagnosa:
   instrumentasi frame goridge — `flags=128` proto didekode sukses lalu
   dibuang). Kontrak PSR-7 pada bridge v4 disediakan class **`PSR7Worker`**;
   worker kini memakai `new PSR7Worker(Worker::create(), $psr17, $psr17,
   $psr17)` dengan guard `class_exists` yang sesuai.

Hasil end-to-end dari ZIP diekstraksi: `GET /` → **200** (`framework:
ZEF Framework v2.14.6`), `GET /zef-health` → 404 JSON terstruktur, log RR
`http log status=200 write_bytes=189`.

### 5. Distribusi ZIP penuh

`zef-framework.zip` dibangun ulang **tanpa pengecualian**: vendor penuh
(≈250 MB tak-kompresi, termasuk `phpstan.phar` 28 MB, `rector`, `phpstorm-stubs`),
semua binary `vendor/bin` (16 stub + `rr`), artefak `build/` (API docs doctum,
log mutasi, kurikulum fase 3–4), cache tool, dan `composer.lock`.

## Regresi

- `composer validate` OK; `composer audit` — *no security vulnerability advisories*.
- PHPUnit **912 test / 13.058 asersi** tetap hijau setelah autoload di-regenerate.
- PHPStan (level max + strict-rules) 0 temuan; PHPCS 0 temuan.
- Smoke bridge: `HttpWorker`, `Psr17Factory`, dan interface PSR-7 terverifikasi
  terekspor dari `vendor/autoload.php`.
- Smoke end-to-end dari ZIP diekstraksi: `vendor/bin/rr --version` OK,
  `bin/zef --self-test` **501/501**, dan `vendor/bin/rr serve` melayani
  request HTTP nyata via worker `bin/worker.php` — `GET /` 200 dengan
  payload JSON framework, `GET /path-asing` 404 JSON terstruktur.
