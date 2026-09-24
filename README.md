# ZEF Framework — Edisi Hexagonal (v2.7.0 → v2.20.0)

[![CodeQL](https://github.com/mbetixz/zef-framework/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/github-code-scanning/codeql)
[![API Documentation Check](https://github.com/mbetixz/zef-framework/actions/workflows/docs-check.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/docs-check.yml)
[![Auto Fix Code Style](https://github.com/mbetixz/zef-framework/actions/workflows/auto-fix.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/auto-fix.yml)
[![ci](https://github.com/mbetixz/zef-framework/actions/workflows/ci.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/ci.yml)
[![Composer Lock Bootstrap](https://github.com/mbetixz/zef-framework/actions/workflows/composer-lock.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/composer-lock.yml)
[![Dependency Review](https://github.com/mbetixz/zef-framework/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/dependency-review.yml)
[![Documentation & GitHub Pages](https://github.com/mbetixz/zef-framework/actions/workflows/pages.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/pages.yml)
[![GitHub Advanced Security](https://github.com/mbetixz/zef-framework/actions/workflows/agents/github-advanced-security/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/agents/github-advanced-security)
[![PHP SAST](https://github.com/mbetixz/zef-framework/actions/workflows/php-sast.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/php-sast.yml)
[![PHPBench Performance](https://github.com/mbetixz/zef-framework/actions/workflows/phpbench.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/phpbench.yml)
[![Release](https://github.com/mbetixz/zef-framework/actions/workflows/release.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/release.yml)
[![Release Drafter](https://github.com/mbetixz/zef-framework/actions/workflows/release-drafter.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/release-drafter.yml)
[![SBOM](https://github.com/mbetixz/zef-framework/actions/workflows/sbom.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/sbom.yml)
[![Secret Scan](https://github.com/mbetixz/zef-framework/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/secret-scan.yml)
[![Zone mutation ratchet](https://github.com/mbetixz/zef-framework/actions/workflows/ci.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/ci.yml)
[![Mutation release gate](https://github.com/mbetixz/zef-framework/actions/workflows/mutation.yml/badge.svg)](https://github.com/mbetixz/zef-framework/actions/workflows/mutation.yml)

Framework PHP 8.4 berarsitektur **Hexagonal (Ports & Adapters)** hasil pemecahan monolith
`zef_framework_v2.7.0.php` (12.639 baris, 1 file) menjadi struktur PSR-4 multi-file per layer.
Rilis lanjutan **v2.8.0** (25+ kelas fitur), **v2.9.0** (Advanced Autowiring Engine),
**v2.10.0** (Enterprise Feature Pack), **v2.11.0** (RadixTree Namespace Container),
**v2.12.0** (Composer toolchain alignment), dan **v2.13.0** (Hardening Release:
PHPStan level max + strict-rules, PHPCS+Slevomat phpDoc strict, Rector agresif,
Deptrac fail-on-uncovered, migrasi 19 suite ke PHPUnit native in-process,
coverage 81.6% via pcov) dan v2.13.1 (penuntasan coverage ≥ 90%: **408 test
PHPUnit / 1248 assertion**, coverage statement **90.04%** dengan gate CI 90%,
Infection PCOV dieksekusi nyata: 8.907 mutan, MSI 59.6% / covered 66.3%,
gate no-regression 55/60, Redis riil 8.0.2
untuk rate-limit store test, plus 12 file unit test baru) serta v2.14.0
(mutation deep-dive ronde 1: **469 test / 11485 assertion**, coverage
statement **91.11%**, MSI 61.8% / covered 67.6% dengan gate 58/62, cluster
terburuk 37%→53%, bug produksi AuthenticationMiddleware ditemukan pipeline
mutasi) serta v2.14.1 (mutation deep-dive ronde 2: **568 test / 11830
assertion**, coverage statement **91.55%**, MSI ~67.0% / covered ~73.0%
dengan gate 64/68, RequestFactory 181→35 escape, Router 136→66, Telemetry
158→74, seluruh 17 slice log escape dipanen ke build/escapes-*.txt) serta
v2.14.2–v2.14.3 (ronde 3 dengan kurikulum `docs/EDGE-CASE-MATRIX.md`:
fase 1 Security/Validation **MSI zona 64.4→84.0**; fase 2b Container core —
Container + AutowireCompilerPass + ContainerResolver — **MSI 3 kelas 67→92,
covered 94, mutation coverage 97%**) serta v2.14.4 (fase 3 Runtime lifecycle:
chunk adapters-runtime-sec **MSI zona 53→80, covered 57→83** — RoadRunnerRuntime,
WorkerAdapter, TinkerSession, AuthenticationMiddleware, SecurityRuntimeMiddleware;
**+138 kill**, dua akar fatal lingkungan uji diakari — signal self-kill 143 dan
routing error_log Infection; **797 test / 12653 assertion**; gate mutasi **69/74**)
ditambah v2.14.5 (fase 4 HTTP/Router/Kernel: chunk adapters-http **MSI zona 75→93,
covered 79→96**, Router **65→85**, Kernel 64→66 — matriks CIDR TrustedProxyMatcher,
kanonisasi persen Uri, siklus moveTo UploadedFile, tata bahasa conditional-GET
ETag, grammar ApiVersionNegotiator, HEAD→GET radix Router, kontrak span/meter
kernel; **+366 kill** dengan 115 test / 405 asersi baru; **912 test / 13058
assertion**; gate mutasi **71/76**), serta v2.14.6 (paket lengkap: bridge RoadRunner
`spiral/roadrunner-http` v4.1 + `nyholm/psr7` sebagai dependency resmi, binary `rr`
v2025.1.15 di `vendor/bin/rr`, config siap-serve `.rr.yaml`, distribusi ZIP tanpa
pengecualian), serta v2.14.7 (fase 5 observability: pipeline telemetry penuh —
zona **85 / 95 / 98 / 86**, escape chunk turun 94→**69** mayoritas ekuivalen
triaged, **+63 test / 349 asersi** baru; lingkungan uji dibuktikan pulih penuh
dari ZIP distribusi), serta v2.14.8 (fase 6 Domain inti: lima chunk — Config
**73→86**, Security **85→95**, Resource **64→89**, Container/Autowiring
**72→89**, core Event/Message/CQRS/Cache/Job/Policy **59→93**; escape 487→**132**
= **+509 kill** pada 2.151 mutan; kurikulum W3C/cron/backoff/resource-filtering/
radix-tree/base32/totp RFC-vectors + counter ≥2^32; dua ronde uji 150 test /
993 asersi), serta v2.14.9 (fase 8–9: **Infrastructure penuh + c3 Observability** —
cache **96/98**, config **94/96**, security-infra **85/89**, obs-infra **90/91**,
c3 Telemetry **83/89**, c3 Tracer/Span/BSP **82/82**, c3 Propagator/Health/Meter
**96/96**; ekstensi APCu/phpredis + server Redis lokal dipasang → 6 test berhenti
skip; inventaris ekuivalen jujur: transport-jaringan OTLP, timing BSP, guard antrean
tak-terjangkau, `::class ≡ get_debug_type`; 18 test baru) — semua aditif, tanpa
mengubah perilaku lama.

> ✅ Terverifikasi: **501/501** assertion self-test · **1794 test PHPUnit native (17914 assertion)** ·
> PHPStan **level max** + strict-rules · Deptrac 0 violations `--fail-on-uncovered` ·
> phpcs+Slevomat 0 violations · coverage statement **94.49%** (gate CI 90%) · mutation gate **85/90** terjaga (MSI global 90.77% / covered 93.38% pada 9.419 mutan) · 417 file lolos `php -l`.
> 📚 **Dokumentasi resmi (v2.17.0):** <https://mbetixz.github.io/zef-framework/> — 10 halaman panduan
> (instalasi, CLI, arsitektur, kualitas/mutasi, deployment) di samping API reference Doctum.
> Ringkasan rilisan terbaru: [`docs/CHANGELOG-v2.20.0.md`](docs/CHANGELOG-v2.20.0.md) — **OpenAPI 3.1 Docs**: spesifikasi otomatis dari route table + validation engine + PHP 8.4 Attributes, serializer JSON/YAML dependency-free, endpoint `/openapi.json` (ETag/304), Swagger UI dev-only, CLI `bin/zef openapi:generate`, export Postman v2.1, validator struktural.
> Rilisan sebelumnya: [`docs/CHANGELOG-v2.19.0.md`](docs/CHANGELOG-v2.19.0.md) — **Event Sourcing**: EventStore port + adapter InMemory/PDO (atomic persist via ambient transaction), `AggregateRoot` + snapshot policy, `Projector` catch-up dengan checkpoint, transactional outbox + relay (retry eksponensial, dead letter).
> Rilisan lebih awal: [`docs/CHANGELOG-v2.18.0.md`](docs/CHANGELOG-v2.18.0.md) — **Database Core**: Query Builder + PDO adapter (nested transaction/savepoint), Migrator (versi + lock TTL), Repository base.
>
> Rincian: [`docs/CHANGELOG-v2.8.0.md`](docs/CHANGELOG-v2.8.0.md) · [`docs/CHANGELOG-v2.9.0.md`](docs/CHANGELOG-v2.9.0.md) · [`docs/CHANGELOG-v2.10.0.md`](docs/CHANGELOG-v2.10.0.md) · [`docs/CHANGELOG-v2.11.0.md`](docs/CHANGELOG-v2.11.0.md) · [`docs/CHANGELOG-v2.14.0.md`](docs/CHANGELOG-v2.14.0.md) · [`docs/CHANGELOG-v2.14.1.md`](docs/CHANGELOG-v2.14.1.md) · [`docs/EDGE-CASE-MATRIX.md`](docs/EDGE-CASE-MATRIX.md) · [`docs/CHANGELOG-v2.14.4.md`](docs/CHANGELOG-v2.14.4.md) · [`docs/CHANGELOG-v2.14.5.md`](docs/CHANGELOG-v2.14.5.md) · [`docs/CHANGELOG-v2.14.6.md`](docs/CHANGELOG-v2.14.6.md) · [`docs/CHANGELOG-v2.14.7.md`](docs/CHANGELOG-v2.14.7.md) · [`docs/CHANGELOG-v2.14.8.md`](docs/CHANGELOG-v2.14.8.md) · [`docs/CHANGELOG-v2.14.9.md`](docs/CHANGELOG-v2.14.9.md) · [`docs/CHANGELOG-v2.15.0.md`](docs/CHANGELOG-v2.15.0.md) · [`docs/CHANGELOG-v2.16.0.md`](docs/CHANGELOG-v2.16.0.md) · [`docs/CHANGELOG-v2.18.0.md`](docs/CHANGELOG-v2.18.0.md) · [`docs/CHANGELOG-v2.19.0.md`](docs/CHANGELOG-v2.19.0.md).

---

## Instalasi

**Persyaratan:** PHP >= 8.4 (ekstensi opsional: `redis`, `apcu`, `mbstring`).

```bash
# Opsi A — tanpa Composer (zero-composer fallback, seperti monolith aslinya)
php bin/zef --self-test
php bin/zef --self-test=v280   # hanya suite fitur v2.8.0 (103 assertion)
php bin/zef --self-test=v290   # hanya suite autowiring v2.9.0 (59 assertion)
php bin/zef --self-test=v210   # hanya suite enterprise v2.10.0 (107 assertion)
php bin/zef --self-test=v211   # hanya suite radix-tree v2.11.0 (87 assertion)

# Opsi B — dengan Composer
composer install
composer test          # PHPUnit native: 408 test (19 suite ZEF in-process + unit tests)
composer coverage:gate # coverage statement via pcov + gate (scripts/ci/assert-coverage.php)
composer phpcs         # phpDoc/type-hint strict (phpcs + Slevomat)
composer phpunit       # (alias) lihat composer test
composer lint          # php -l seluruh file first-party
composer stan          # PHPStan level MAX + strict-rules + baseline frozen
composer deptrac       # conformance arsitektur hexagonal
composer format:check  # php-cs-fixer (PER-CS2.0 + Symfony)
composer rector:check  # usulan upgrade PHP 8.4 (dry-run)
composer audit         # kebijakan paket abandoned
composer bench         # PHPBench: container get() 0.6us (singleton)
composer docs          # Doctum API docs -> build/api
composer serve         # dev server di 0.0.0.0:8080
```

> Catatan: proyek ini **tidak butuh** `composer install` untuk berjalan. Autoloader classmap
> statis (`autoload/zef_autoload.php`) sudah mencakup seluruh 250 kelas. Jika Composer
> digunakan, autoloader tersebut tetap terdaftar lewat `autoload.files` dan aman digandakan.
> Interface PSR resmi (`psr/*`) dipakai secara otomatis bila tersedia; jika tidak, shim
> kondisional di `src/Compat/Psr/` yang aktif.

## Quick Start

### 1. Development server (HTTP)

```bash
bin/zef --serve 0.0.0.0:8080
# atau
php -S 0.0.0.0:8080 public/index.php
```

Rute bawaan aplikasi demo:

| Rute                    | Handler                  | Sumber           |
|-------------------------|--------------------------|------------------|
| `GET /`                 | HomeHandler              | `modules/Core`   |
| `GET /about`            | AboutHandler             | `modules/Core`   |
| `GET /health`           | AggregateHealthHandler   | `modules/Health` | (v2.8.0 — 503 saat degraded)
| `GET /health/live`      | LiveHandler              | `modules/Health` |
| `GET /health/ready`     | ReadyHandler             | `modules/Health` |
| `GET /metrics`          | MetricsHandler           | `modules/Health` | (v2.8.0 — Prometheus)
| `GET /toko`             | TokoHandler              | `plugins/Toko`   |
| `GET /toko/produk/{id}` | ProdukDetailHandler      | `plugins/Toko`   |

Rute `404`, `400` (pelanggaran constraint `{id:int}`), dan `405` juga aktif.

### 2. Self-test (501 assertion)

```bash
bin/zef --self-test
```

### 3. RoadRunner (produksi)

Bridge (`spiral/roadrunner-http` v4.1 + `nyholm/psr7`) dan binary `rr` v2025.1.15
sudah termasuk dalam paket — konfigurasi siap di `.rr.yaml`:

```bash
vendor/bin/rr serve        # worker: bin/worker.php, listen 0.0.0.0:8080
```

Installasi manual (opsional, untuk proyek turunan):

```bash
composer require spiral/roadrunner-http nyholm/psr7
vendor/bin/rr get-binaries  # atau unduh release dari GitHub
```

### 4. Memakai framework dari kode

```php
<?php
require 'autoload/zef_autoload.php';

use Zef\App\Bootstrap;

$app    = Bootstrap::createApp(debug: true);
$app->setTrustedHosts(['localhost', '127.0.0.1']);
$app->addProvider(new \Zef\Module\Core\ConfigProvider());
$app->addProvider(new \Zef\Plugin\Toko\ConfigProvider());

// CLI lain: command bus, cache, telemetry, job worker — semua tersedia
$cache = new \Zef\Framework\Cache\InMemoryCache(
    new \Zef\Framework\Cache\InMemoryCacheStore(),
    new \Zef\Framework\Cache\SystemCacheClock(),
);
```

## Dokumentasi

Dokumentasi resmi diterbitkan otomatis ke GitHub Pages oleh
`.github/workflows/pages.yml` (setiap push ke `main`) dan dapat dibaca sebagai
situs di **<https://mbetixz.github.io/zef-framework/>** atau langsung dari Markdown
di repositori:

| Dokumen | Isi |
|---------|-----|
| [`docs/README.md`](docs/README.md) | indeks dokumentasi |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | persyaratan, Composer & zero-composer, RoadRunner, Docker/K8s, variabel lingkungan |
| [`docs/CLI.md`](docs/CLI.md) | referensi lengkap `bin/zef` (`--self-test`, `--serve`, inspector, 10 generator, `tinker`) |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | pemecahan monolith, layer hexagonal, aturan arah dependensi |
| [`docs/QUALITY.md`](docs/QUALITY.md) | tujuh gerbang kualitas, **mutation testing (MSI per area)**, triage mutan ekuivalen |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | RoadRunner persistent worker, state lintas-request, observabilitas, keamanan runtime |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | rencana & status fitur |
| [`docs/EDGE-CASE-MATRIX.md`](docs/EDGE-CASE-MATRIX.md) | kurikulum uji edge-case per fase kampanye mutasi |
| [`docs/security/php-sast.md`](docs/security/php-sast.md) | panduan SAST PHP |

Situs dokumentasi dirakit oleh `scripts/build_docs.php` (Markdown → HTML statis,
`parsedown/parsedown` mode aman sebagai dependensi transitif Doctum); API reference
Doctum di-*merge* ke `build/docs/api` sehingga dokumentasi resmi dan API dapat
diakses dari satu akar.

```bash
composer docs                  # API reference -> build/api
php scripts/build_docs.php     # situs dokumentasi -> build/docs
```

## Struktur Direktori

```
zef-framework/
├── autoload/zef_autoload.php   # classmap statis 353 kelas (zero-composer fallback)
├── bin/
│   ├── zef                     # CLI: list, --self-test, --serve, route:list, make:* (10 generator), module:list, plugin:list, config:show, tinker
│   └── worker.php              # worker RoadRunner (produksi)
├── public/index.php            # entrypoint HTTP (web SAPI)
├── src/                        # framework inti — 4 layer hexagonal + compat
│   ├── Compat/Psr/             # shim PSR kondisional (23 interface/kelas)
│   ├── Domain/                 # port, kontrak, VO, validator (132)
│   ├── Application/            # mesin orkestrasi in-process (50)
│   ├── Infrastructure/         # adapter outbound: cache, redis, otlp, aes, prometheus (19)
│   └── Adapters/               # adapter inbound: http, router, kernel, runtime (32)
├── app/                        # aplikasi demo (Bootstrap + middleware)
├── modules/                    # modul demo: Core, Health (+ /health, /metrics)
├── plugins/                    # plugin demo: Toko
├── tests/                      # self-test suite (501 assertion, lihat bagian Testing)
├── deploy/                     # Dockerfile, docker-compose.yml (v2.8.0), k8s/ (v2.10.0)
├── .github/workflows/ci.yml    # CI: lint + self-test (v2.8.0)
├── docs/                       # ARCHITECTURE, CHANGELOG-*, ROADMAP
├── scripts/lint.php            # lint seluruh file PHP
├── composer.json               # scaffolding PSR-4 penuh
└── README.md
```

## Sorotan Fitur v2.8.0

| Area | Fitur | Kelas utama |
|------|-------|-------------|
| Container | Tagged services | `TaggedServiceLocator` |
| Router | Route naming + URL generation | `UrlGenerator`, `Router::patternFor()` |
| HTTP | Conditional GET (ETag/304) | `ETagMiddleware` (opt-in) |
| HTTP | RFC 9457 Problem Details | `ProblemDetails` |
| API | Pagination offset/page/cursor | `PageRequest`, `PageSlice`, `Cursor` |
| Observability | Export Prometheus `GET /metrics` | `PrometheusRenderer` |
| Health | Indikator kustom + agregat `GET /health` | `HealthIndicatorInterface`, `HealthAggregator` |
| Cache | Tags, L1/L2, lock lease | `TaggableCache`, `TieredCache`, `InMemoryLockStore` |
| Jobs | Scheduler cron/fixed-interval | `Scheduler`, `CronExpression`, `FixedIntervalSchedule` |
| Security | AES-256-GCM + TOTP (RFC 6238) | `AesGcmEncryptor`, `Totp`, `Base32` |
| Validation | Rules engine field-based | `Validator`, `FieldRules` |
| Messaging | Dedup at-least-once | `DeduplicatingMiddleware` |
| DX | `zef list`, `make:*` (10 generator), `module:list`, `plugin:list`, `config:show` | `src/Infrastructure/Console` + `bin/zef` |
| DevOps | Docker, Compose, CI | `deploy/`, `.github/` |

## Sorotan Fitur v2.9.0 — Advanced Autowiring Engine

| Area | Fitur | Kelas utama |
|------|-------|-------------|
| Attributes | `#[Inject]`, `#[Value]`, `#[Target]` | `Autowiring\Inject`, `Value`, `Target` |
| Resolusi | Interface binding berlapis + autowire rekursif | `AutowireCompilerPass` |
| Variadic | Koleksi seluruh implementasi service | `AutowireCompilerPass::collectImplementations()` |
| Graf | Deps lengkap → validasi asli tetap satu gerbang | `AutowireMetadata`, `DependencyGraphValidator` |
| AOT | Kode closure murni + export/load berkas | `AutowireAotCompiler`, `ReflectionMetadataExtractor` |

```php
$pass = new AutowireCompilerPass(configValues: ['db.host' => 'localhost'], module: 'billing');
$result = $pass->process($container, [PaymentService::class]);
AutowireAotCompiler::export($result, 'var/cache/zef-aot-billing.php'); // opsional (cold start)
$container->validateAndFreeze();   // graf dependensi kini lengkap
```

Detail lengkap: [`docs/CHANGELOG-v2.9.0.md`](docs/CHANGELOG-v2.9.0.md).

## Sorotan Fitur v2.10.0 — Enterprise Feature Pack

| Area | Fitur | Kelas utama |
|------|-------|-------------|
| Container | Contextual binding `when()->needs()->give()` | `ContextualBindingBuilder` |
| Container | Decoration chain + deferred providers + events | `Container::decorate/registerProvider/onResolved` |
| Router | Groups/prefixes bersarang + fallback 404 | `Router::group()`, `Router::fallback()` |
| Router | Route cache kompilasi (file murni, atomic write) | `RouteCache`, `Router::fromCompiledArray()` |
| HTTP | API versioning (path > header > query > default) | `ApiVersionNegotiator`, `ApiVersion` |
| HTTP | Form request objects (rules engine terintegrasi) | `FormRequest` |
| Resource | Sorting + filtering whitelist-wajib | `SortSpec`, `FilterSpec`, `SortKey`, `FilterCondition` |
| Security | Rotasi kunci AES-256-GCM | `RotatingKeyRing` |
| Validation | Pesan error terlokalisasi (fallback chain) | `MessageCatalog`, `ValidationTranslator` |
| DX/DevOps | Tinker REPL + K8s manifests | `TinkerSession`, `bin/zef tinker`, `deploy/k8s/` |

```php
$c->when(BillingService::class)->needs(PaymentGateway::class)->give('gateway.stripe');
$c->decorate('mailer', fn($ctx, $inner) => new LoggingMailer($inner, $ctx->get('logger')));
$r->group(['prefix' => '/api/v2', 'name' => 'api.v2.'], fn($r) => $r->add('GET', '/users', 'user.index', name: 'users'));
```

Detail lengkap: [`docs/CHANGELOG-v2.10.0.md`](docs/CHANGELOG-v2.10.0.md).

```bash
bin/zef list                            # katalog semua command (+ --json)
bin/zef route:list                      # inspeksi rute + nama
bin/zef make:module katalog             # scaffold modul baru
bin/zef make:plugin Loyalitas           # scaffold plugin lengkap (Provider+Service+Handler)
bin/zef make:handler Produk --module=katalog --path=/katalog/produk
bin/zef make:middleware Tracing         # scaffold middleware PSR-15 (src/Middleware)
bin/zef make:config Cache --module=katalog   # ConfigProvider modul (mekanisme config ZEF)
bin/zef make:command PlaceOrder --module=katalog   # CQRS command + handler
bin/zef make:query FindOrder --module=katalog      # CQRS query + handler
bin/zef make:entity Pesanan --module=katalog       # entitas Domain (identity + equals)
bin/zef make:valueobject Uang --module=katalog     # final readonly value object
bin/zef make:service Stok --module=katalog         # service aplikasi + wiring snippet
bin/zef module:list                     # modul terdaftar (boot nyata)
bin/zef plugin:list                     # plugin on-disk
bin/zef config:show katalog.cache       # dump config teragregasi (JSON-safe)
bin/zef tinker                          # REPL dengan $app + $container siap pakai
```

## Testing

Suite self-test framework hidup di `tests/` dan dijalankan lewat `bin/zef --self-test`
(tanpa dependensi eksternal — PHPUnit tidak diperlukan). Total **501 assertion** dalam
19 suite ber-key:

| Berkas | Isi |
|--------|-----|
| `tests/CliRunner.php` | Runner + 15 suite baseline (PSR contracts, routes, container, security, hardening, regresi v2.6.0 & v2.7.0, dll.) |
| `tests/V280FeatureSuite.php` | **Suite khusus v2.8.0** — 13 sub-suite, 103 assertion untuk seluruh fitur roadmap v2.8.0 (tagged services, URL generator, ETag, ProblemDetails, pagination, Prometheus, health, cache, scheduler, AES-GCM, TOTP, validator, dedup) |
| `tests/V290AutowireSuite.php` | **Suite khusus v2.9.0** — 11 sub-suite, 59 assertion untuk Advanced Autowiring Engine (attributes, interface binding, variadic, graf dependensi, AOT cold-start, frozen guard) |
| `tests/V2100EnterpriseSuite.php` | **Suite khusus v2.10.0** — 13 sub-suite, 107 assertion untuk Enterprise Feature Pack (contextual binding, decoration, providers, events, groups, route cache, fallback, API versioning, sort/filter, key ring, i18n, form request, tinker) |
| `tests/V2110RadixTreeSuite.php` | **Suite khusus v2.11.0** — 8 sub-suite, 87 assertion untuk RadixTree Namespace Container (struktur tree & kompresi, prefix query, namespace scope policy, getByPrefix, namespace fallback, AOT round-trip, lifecycle freeze, edge semantics) |
| `tests/V270*.php` | Fixture konkret untuk suite regresi v2.7.0 |

```bash
bin/zef --self-test            # seluruh 501 assertion (19 suite)
bin/zef --self-test=v280       # hanya suite fitur v2.8.0 — 103 assertion
bin/zef --self-test=v290       # hanya suite autowiring v2.9.0 — 59 assertion
bin/zef --self-test=v210       # hanya suite enterprise v2.10.0 — 107 assertion
bin/zef --self-test=v211       # hanya suite radix-tree v2.11.0 — 87 assertion
bin/zef --self-test=v27        # regresi v2.7.0 (key v270; substring match)
bin/zef --self-test=router     # suite apa pun yang key/labelnya mengandung 'router'
composer test                  # sama dengan --self-test penuh
```

Filter bersifat case-insensitive dan dicocokkan substring terhadap key maupun label suite
(`psr, routes, container, concurrency, security, request, router, pipeline, psr7, hardening,
json, gate, beta3, v260, v270, v280, v290, v210, v211`); filter yang tidak cocok apa pun keluar dengan
status 1 dan mencetak daftar key yang tersedia.

## Variabel Lingkungan

| Variabel                    | Default              | Keterangan                                  |
|-----------------------------|----------------------|---------------------------------------------|
| `ZEF_DEBUG`                 | `0`                  | Mode debug (diagnostik error + pesan detail)|
| `ZEF_MAX_BODY_BYTES`        | bawaan framework     | Batas ukuran request body (413 bila lebih)  |
| `ZEF_TRUSTED_HOSTS`         | `localhost,127.0.0.1,::1,zef.test` | Daftar host tepercaya (CSV)   |
| `ZEF_SECURITY_CSRF_SECRET`  | —                    | Secret CSRF >= 32 byte (aktifkan CSRF)      |
| `ZEF_WORKER_MAX_JOBS`       | `0` (tanpa batas)    | Kapasitas job per worker RoadRunner         |
| `ZEF_WORKER_MEMORY_LIMIT`   | `0` (nonaktif)       | Batas memori worker RoadRunner (byte)       |

## Pemetaan dari Monolith

Setiap deklarasi dipindahkan **verbatim** (byte-exact, terverifikasi round-trip) ke satu file
per kelas. Namespace **tidak diubah** — `Zef\Framework\Container\Container` tetap bernama sama;
yang berubah hanyalah lokasi fisiknya di pohon direktori, dikelompokkan ke layer hexagonal.
Rincian aturan pemetaan dan arah dependensi antar-layer ada di
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
