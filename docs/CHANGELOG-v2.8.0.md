# ZEF Framework — Changelog v2.8.0 (Roadmap Continuation)

> Rilis fitur pertama setelah refactor hexagonal v2.7.0. Seluruh perubahan bersifat
> **aditif**: tidak ada perilaku lama yang diubah. Konstanta `ZefVersion::VERSION`
> sengaja tetap `2.7.0` agar respons wire (termasuk `GET /about`) tetap byte-identik
> dengan baseline monolith yang ter-audit — verifikasi ganda membuktikannya
> (145 assertion lama tetap hijau, 11 rute HTTP byte-identik).

## Verifikasi Rilis

| Pemeriksaan | Hasil |
|---|---|
| `php -l` seluruh file | 287 file, 0 gagal |
| Self-test suite lama (baseline monolith) | 145/145 PASSED |
| Suite fitur baru (`v2.8.0 feature suite`) | 103 assertion PASSED |
| Total self-test (`bin/zef --self-test`) | **248 PASSED / 0 FAILED** |
| HTTP compare vs monolith (status + body) | **11/11 byte-identik** |

## Fitur Baru

### Container (PSR-11)
- **Tagged services** — `Zef\Framework\Container\TaggedServiceLocator`: sisi baca untuk
  `ServiceDefinition::$tags` yang sebelumnya tersimpan tanpa pemanfaatan. API:
  `idsFor()`, `hasTag()`, `resolveAll()`, `resolveOne()` (menolak tag ambigu),
  `requireOne()`. Terdaftar otomatis sebagai singleton oleh `Application::boot()`.

### Router
- **Route naming & reverse routing** — `RouteDefinition` menerima `name`
  (config rute: `'name' => 'users.show'`); `Router::patternFor()`, `hasRouteName()`,
  `routeNames()`; generator URL `Zef\Framework\Router\UrlGenerator` dengan
  substitusi parameter tervalidasi constraint (pelanggaran constraint melempar
  `RouteConstraintException`, param ekstra/minus ditolak, nilai di-`rawurlencode`).

### HTTP & API
- **ETag / Conditional GET (RFC 9110 §13)** — `ETagMiddleware` (PSR-15, opt-in):
  ETag kuat SHA-256, evaluasi `If-None-Match` (`*`, daftar, `W/` lemah),
  `If-Modified-Since` vs `Last-Modified`, respons 304 tanpa body.
- **RFC 9457 Problem Details** — `ProblemDetails`: factory `application/problem+json`
  dengan member standar + ekstensi tervalidasi.
- **Pagination toolkit** — `PageRequest` (offset/limit + page/per-page, hard cap 100,
  fallback lunak dari query), `PageSlice` (meta navigasi), `Cursor` (opaque base64url
  dengan checksum salt — taman tamper terdeteksi).

### Observability & Health
- **Prometheus export** — `PrometheusRenderer` (text format 0.0.4; sanitasi nama
  metrik/label, escape nilai, series `_sum`/`_count` untuk observasi) + endpoint demo
  `GET /metrics` di modul Health.
- **Custom health indicators** — port `HealthIndicatorInterface` + VO
  `HealthCheckResult` (Domain), orkestrator `HealthAggregator` (Application,
  probe crash terkontain → degraded, bukan 500), endpoint agregat `GET /health`
  (503 saat degraded). Indikator dikumpulkan via tag `health.indicator` —
  demonstrasi end-to-end tagged services.

### Cache
- **Cache tags** — `TaggableCache`: `setWithTags()`, `invalidateTag()`, `tagsFor()`
  (indeks maju + balik, prefix kunci ter-reserved).
- **Multi-tier L1/L2** — `TieredCache`: write-through, promosi read, TTL L1 ter-cap.
- **Lock primitive** — port `LockStoreInterface` + `InMemoryLockStore` (lease TTL,
  ownership, re-entrant, refresh; jam ter-inject untuk uji deterministik). Fondasi
  stampede prevention dan distributed lock Redis berikutnya.

### Job Queue
- **Scheduler** — `Scheduler` (registrasi job berulang → `JobQueueInterface`,
  cursor per-registrasi, catch-up ter-cap, lazy anchor epoch-aligned) dengan dua
  policy: `FixedIntervalSchedule` (interval epoch-aligned) dan `CronExpression`
  (cron 5 field, dukungan `*`, daftar, rentang, langkah; UTC; OR-semantics dom/dow
  klasik; scan hingga 1 siklus kabisat penuh).

### Security
- **Enkripsi AEAD** — port `EncryptionInterface` + `AesGcmEncryptor`
  (AES-256-GCM via openssl; IV 96-bit acak per pesan, tag 128-bit, format
  `zefenc1.<iv>.<tag>.<ct>` siap rotasi; kunci raw/hex/base64 32 byte tervalidasi).
- **MFA TOTP** — `Totp` (RFC 6238, SHA-1/256/512, 6–8 digit, jendela ±N,
  `hash_equals`) + `Base32` (RFC 4648, tanpa padding). Seluruh vektor resmi
  RFC 6238 Appendix B lolos di self-test.

### Validation
- **Rules engine** — `Validator` + `FieldRules` (required, type*, min/max,
  minLength/maxLength, email, uuid, in, pattern ReDoS-guarded, custom, nullable)
  menghasilkan `ValidationResult` + `ValidationError` yang stabil untuk API.

### Messaging
- **Dedup middleware** — `DeduplicatingMiddleware`: redelivery at-least-once menjadi
  efektif sekali per `messageId` (hasil pertama di-cache via idempotency store).

### Developer Experience
- `bin/zef route:list` — tabel seluruh rute terdaftar (boot aplikasi nyata).
- `bin/zef make:module <name>` / `make:handler <Name> [--module=…] [--path=…]` /
  `make:middleware <Name>` — scaffolding file PSR-15 + petunjuk wiring, anti-timpa.

### Deployment
- `deploy/Dockerfile` (php:8.4-cli-alpine, lint + self-test saat build),
  `deploy/docker-compose.yml` (app + Redis, healthcheck `/health/ready`),
  `.github/workflows/ci.yml` (lint + self-test di PHP 8.4).

### Testing
- **Suite v2.8.0 berkas tersendiri** — `tests/V280FeatureSuite.php`: 13 sub-suite
  (103 assertion) dipindah verbatim dari `tests/CliRunner.php` sehingga fitur-fitur
  v2.8.0 punya suite yang terlihat dan bisa dijalankan mandiri. Counter assertion
  tetap terpusat di `CliRunner`; `CliRunner::ok()`/`throws()` dilebarkan menjadi public.
- **Filter suite** — `bin/zef --self-test=<key>` menjalankan hanya suite yang cocok
  (substring case-insensitive terhadap key/label: `psr, routes, container, concurrency,
  security, request, router, pipeline, psr7, hardening, json, gate, beta3, v260, v270,
  v280`), mis. `--self-test=v280`. Filter tanpa kecocokan keluar status 1 beserta daftar
  key. Output `CliRunner::run()` menampilkan filter aktif pada banner.
- Classmap zero-composer diperbarui: 283 kelas (tambahan `Zef\Test\V280FeatureSuite`).

## Catatan Migrasi

Tidak ada. Semua API lama tidak berubah; fitur baru bersifat opt-in (middleware ETag
dan route name/`UrlGenerator` hanya aktif jika dipakai; endpoint `/health` dan
`/metrics` adalah rute baru pada modul demo Health). Jika memakai Composer, jalankan
`composer dump-autoload` setelah menarik berkas; jika memakai autoloader zero-composer,
classmap statis sudah diperbarui (283 kelas).
