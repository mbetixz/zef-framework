# CHANGELOG v2.15.0 — Fase 10: Zona Sisa Container + Kernel/Application + Middleware (Masuk Scope)

> Fase penutup kampanye mutation testing. Gate mutasi global dinaikkan dari 77/82 → **85/90**.
> Terverifikasi **full-run Infection 9.032 mutan: MSI 90.4% / Coverage 97% / Covered MSI 93.1%**.

## Highlight

- **Gate mutasi naik ke `--min-msi=85 --min-covered-msi=90`** — target akhir kampanye tercapai.
- **src/Middleware masuk scope mutasi** (sebelumnya di luar `source.directories`): CORS,
  SecurityHeaders, GlobalErrorHandler, TimingMiddleware, ConfigProvider kini diuji mutasi.
- **35 test / 80+ asersi baru** (3 file kurikulum Fase 10): `EdgeMatrixF10ContainerTest`,
  `EdgeMatrixF10KernelTest`, `EdgeMatrixF10EmitterTest`.
- Suite: **1.432 test / 16.358 asersi** (5 skip terdokumentasi). PHPStan max+strict 0,
  PHPCS 0, cs-fixer 0, rector 0.

## Zona & hasil (baseline → pasca-Fase 10)

| Zona | MSI awal | MSI akhir | Catatan |
|------|----------|-----------|---------|
| Application/Container | 88/92 | **96/97/99** | 67 escape → 5; ~35 anotasi ekuivalen terjustifikasi |
| Adapters/Kernel/Application.php | 63/— | **92/—** | 56 escape + 7 NC → 10 escape + 3 NC; OTLP sink E2E |
| Kernel kecil (MiddlewareDefinition, ResponseEmitter) | — | ditambah | kurikulum emitter chunk 8192, 204/304, definisi middleware |
| Adapters/Http, Router, Runtime/Security | 93.6 / 79 | (tetap) | sisa escape tertriase — lanjutan fase berikutnya |

## Kurikulum edge-case (1 test = 1+ pembunuh mutan)

- **Container**: AOT load reindex deps string-keyed + default shared per lifetime; koleksi
  variadic lintas provider (id interface, factory invokable-object, array-callable, union-return
  diabaikan tanpa fatal); nullable-default satu argumen (eval factory hasil autowire); resolusi
  pasca-freeze via plan; budget dekorasi pecah tepat di `count == budget`; singleton
  `shared=false` wajib resolusi ulang; guard kedalaman tepat di budget; lifetime tak dikenal
  ditolak; guard RequestScope has/get/set/close; tag grammar full-match; view registry endpoints.
- **Kernel/Application**: identitas logger & policy injeksi; graf cache service (kapasitas
  10000 + deps eksak + alias); kanonisasi trustedHosts (map/filter/values, kunci list ketat);
  `setMaxCrossModuleRefs` meneruskan; config merged modul `framework` mengalir ke policy
  (coercion `not-int` → 0 tanpa TypeError); tiga bus beku pasca-boot; warmSingletons tepat
  sekali; lifecycle modul boot/start/shutdown + idempoten; fallback 500 pra-boot; HEAD
  case-insensitive; emit menulis body; graf observability (Tracer/Meter/TelemetryLogger deps
  eksak); TTL CommandBus 3600; **sesi OTLP fork-server**: span atribut `http.request.method`,
  `status_code`, durasi dalam skala detik, status OK/ERROR (boundary 500), deskripsi eksepsi,
  event exception, log lifecycle `event.name` + `trace_id`, flush per-request.
- **ResponseEmitter**: body 20.000 byte terkirim utuh (chunk 8192); 204/304 tanpa body.

## Triage ekuivalen (anotasi `@infection-ignore-all` terjustifikasi di source)

~35 mutan terbukti ekuivalen/tak-terjangkau, antara lain: `isset()`-guard pada peta flag
(nilai tak terbaca); cek budget dekorasi ganda yang redundan; `scopeOf(dep)` yang menentukan
pair sehingga komposisi `edgeKey` tak terobservasi; jalur fallback `throwNotFound` identik;
kunci `getRegisteredIds()` yang mustahil duplikat; `bestLen` fallback prefix dengan prefix
non-kosong; guard duplikat binding kontekstual yang tak terjangkau karena penulisan-ulang deps;
kolektor tipe tanpa duplikat; flag merge `ConfigAggregator` yang self-merging via `get()`.

## Infrastruktur & gotcha yang didokumentasikan

- redis-server 8.0.2 diekstrak ke prefix lokal (`redis-local/root`) + LD_LIBRARY_PATH,
  daemon di 127.0.0.1:6399 — suite Redis/APCu tetap jalan pasca reset sandbox.
- Pola eksekusi chunk Infection background-detached + polling (zona berat >9 menit
  foreground, mis. Application.php 7m32s, full-run 1j28m).
- Gotcha kurikulum: kunci array string-numerik menabrak auto-index (elemen tertimpa),
  PSR-4 config provider dinamespace per-modul (`framework.*`, `middleware.stack`),
  OTLP `status.message` berupa string polos, `shared=true` hanya sah untuk singleton,
  variadic promoted property tak valid di PHP 8.4.

## Deliverable

- Source + tests: `/home/z/my-project/download/zef-framework/`
- Arsip: `zef-framework-v2.15.0.zip` (ZIP normal, kompresi standar)
