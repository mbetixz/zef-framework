# ZEF Framework v2.14.5 — Edge-Case Matrix fase 4 (HTTP/Router/Kernel)

Ronde 3 deep-dive berlanjut. Fase 4 menyasar dua chunk terberat sisa: `adapters-http`
(1.453 mutan, baseline MSI 75) dan `adapters-router-kernel` (877 mutan, baseline MSI 65).
Kurikulumnya adalah tambangan mutan lolos (537 escape) dari baseline fase 4 — setiap
test baru mewakili skenario adversarial nyata, bukan produksi mekanis.

## Hasil terukur (Infection PCOV, threads=2)

| Zona | Mutan | MSI | Covered MSI | Escape | Not-Covered |
|---|---:|---:|---:|---:|---:|
| adapters-http (baseline → final) | 1453 | 75 → **93** | 79 → **96** | 277 → **51** | 78 → **40** |
| Adapters/Router (baseline → final) | 472 | 65 → **85** | 68 → **87** | ~113 → **57** | → **10** |
| Adapters/Kernel (baseline → final) | 405 | 64 → **66** | 68 → **69** | ~147 → **119** | → **17** |
| **Total fase 4** | 2330 | — | — | **537 → 227** | 123 → **67** |

**+366 mutan dibunuh** (killed 1.659 → 2.025) lewat 115 test / 405 asersi baru.
Gate mutasi `composer.json` naik 69/74 → **71/76**. PHPUnit kini **912 test /
13.058 asersi** (+115 test, +405 asersi; 13 skip terdokumentasi).

## Kurikulum yang dieksekusi (tests/Unit/EdgeMatrixHttpTest.php — 82 test)

- **Uri**: kanonisasi persen (`%2f`→`%2F`, `%G4`→`%25G4`, `%2`→`%252`), sub-delim
  RFC 3986 di path, userinfo `user@:pass` dengan encoding kolon password, host
  bracket IPv6 + trim + lowercase, validator port 1..65535 dengan null-clear,
  `Untrusted host` bila daftar trusted hosts terkunci, immutabilitas ketat
  seluruh `with*` (pembunuh klaster `CloneRemoval`), `__toString` komposisi
  authority + path relatif, host reg-name `_` dan `Invalid URI host control
  characters.`, `http:///path` → `Unable to parse URI`.
- **Stream**: matriks mode (write-only `'w'` tidak terbaca dan terstringkan
  kosong; read-only menolak `write`), siklus close/detach (idempoten, `Stream
  detached.`, capability collapse), `read(0)`/`read(-1)`.
- **LimitedInputStream**: batas body tepat N vs N+1 → `PayloadTooLargeException`,
  reset counter via `seek`/`rewind` (bug fix #1), getSize ter-cap, read-only,
  `(string)` aman untuk body oversize dan inner rusak (fixture `BrokenReadStream`).
- **UploadedFile**: guard error/double-move/getStream, `Destination directory
  does not exist` persis, sukses = konten benar + tanpa artefak `.zef-tmp-*` +
  stream tertutup; gagal finalisasi (rename ke direktori) = pesan `Unable to
  finalize` + cleanup tmp + posisi stream dipulihkan (membunuh `FalseValue`
  `$success`); roundtrip multi-chunk 20×8192+11 byte; size negatif ditolak.
- **TrustedProxyMatcher**: matriks CIDR (`/0`, `/32`, `/128`, `/33`, `/129`,
  `/-1`, campuran keluarga IPv4/IPv6, trim sisi kedua), lanjut-scan saat entri
  sampah/`/8/9`/non-digit — pembunuh `continue→break`.
- **ETagMiddleware**: strong ETag SHA-256, `If-None-Match` (`*`, `W/`, `w/`,
  list koma, kandidat kosong), IMS ≤ (timestamp persis = 304), IMS rusak
  (trailing junk, hari tidak valid) = tanpa prasyarat, ETag handler tidak
  ditimpa, method `get` lowercase tetap dihormati (fixture
  `LowercaseMethodRequest`), IMS hanya tanpa-ETag.
- **ApiVersionNegotiator**: guard konstruktor (kosong, non-scalar `got: array`,
  scalar `got: 5`, token 17 byte, default di luar daftar), rantai prioritas
  path→header→query→default, token 16 byte memecah path persis, header >256 B
  dan query >64 B di-drop diam, pesan `API version '9' is not supported.
  Supported versions: 1, 2.` dan `No API version provided (… X-Version …
  ?ver= …)` persis.
- **Psr17Factory/ServerRequest/MessageBase**: default `createResponse()` 200,
  mode stream (`zz`, `r!`, `q+r`, `wbx`) ditolak + pesan warning dipertahankan,
  resource write-only ditolak, upload stream tertutup ditolak; Host header
  eksplisit menang, uploaded-tree guard di kedalaman apa pun (pembunuh
  `continue→break`), kontrak header replace/append/remove case-insensitive,
  protocol version guard.

## Kurikulum yang dieksekusi (tests/Unit/EdgeMatrixRouterKernelTest.php — 33 test)

- **Router**: tata bahasa placeholder (`{a}{b}`, `{id}x`, `{1a}`, `{}` →
  `Invalid route segment`), pesan duplikat `it collides with /users/{id:int}`,
  dynamic-then-static harus cocok ekor statik, sibing `{v:int}` vs `{v}` pada
  radix edge terpisah, **HEAD→GET tetap bekerja meski POST sort lebih dulu**
  (pembunuh `continue→break` di loop kandidat), allow-list 405 `GET, HEAD,
  POST`, constraint-400 hanya bila method cocok, prioritas grup menggeser
  precedensi, prefix nama grup tidak menciptakan entri untuk route tanpa nama,
  budget `setMaxRoutesBudget(2)` ditegakkan persis.
- **Compiled restore**: `routes` wajib ada, `maxRoutesBudget` 0→clamp 1,
  fallback `''`→null, constraints asing difilter, roundtrip + `matchOrFallback`
  (fallback hanya menutup 404; 400/405 tetap dilontarkan).
- **RouteCache**: write+load roundtrip (routes, nama, constraint), guard file
  hilang/non-array, `Cannot create route-cache directory` saat parent berupa
  file (dengan error-handler terbatas untuk warning `mkdir`).
- **RouteDefinition/UrlGenerator**: guard lengkap + koersi `fromArray`
  (prioritas `'7.5'`→7, non-numerik ditolak); generator: param hilang/lebih,
  pelanggaran constraint, rawurlencode, Stringable, pesan `must be scalar or
  Stringable, got array`, `Unknown route name`.
- **Kernel**: freeze pasca-boot (addProvider/addModule/setTrustedHosts/
  setTrustedProxies), sanitasi trusted-proxies (`''` difilter, int di-strval,
  re-index) teramati dari atribut request, `handleGlobals` 400 debug membeberkan
  `Malformed Host header.` vs `Invalid request.`, 413 untuk body oversize,
  HEAD = body kosong, exception handler dicatat (`zef.http.errors.total` +
  `request.failed`, tanpa `request.completed`) lalu dilempar, seri meter sukses
  (`zef.http.requests.total` per method+status, histogram durasi),
  traceparent muncul saat `ZEF_OTEL_ENABLED` dan absen saat mati, span
  `zef.handler.execute` berstatus ERROR pada 500 vs OK pada 499 + atribut
  `zef.handler`/`http.route` (via `InMemorySpanExporter` → `SpanData`),
  payload 404/405/400 Dispatcher persis (termasuk header `Allow`), status
  emitter 100/599 tersambung (`http_response_code`), pipeline middleware
  urutan enter/exit + `withMiddleware`.

## Triage sisa escape (jujur, tidak dipalsukan)

- **ResponseEmitter (37)**: seluruh klaster rekonsiliasi Content-Length dan
  loop `header()` hanya dapat diamati lewat SAPI header (`headers_list()`
  tidak merekam `header()` di CLI). Struktur `if (!headers_sent())` membuat
  efeknya tidak teramati di harness CLI; dinyatakan setara-lingkungan, bukan
  dibunuh palsu.
- **Application (63)**: internal span saat OTEL mati (NoopSpan tidak menyimpan
  atribut — pengujian span sudah dilakukan pada mode enabled), Coalesce
  konstruktor pada default services, guard platform-invariant, dan label meter
  yang di-bucket `other` oleh allowlist CounterMeter (perilaku terkunci, bukan
  bug).
- **Dispatcher/Definition/Bootstrapper/Pipeline (39)**: observabilitas
  `MethodCallRemoval` pada jalur yang membutuhkan SAPI, konfigurasi
  `middleware.stack` top-level yang hanya hidup di bawah modul framework,
  sentinel dedup defensif.

## Toolchain & regresi

PHPStan max+strict-rules 0 temuan baru; cs-fixer PER-CS2.0 + Rector diterapkan
pada kedua file test; PHPCS Slevomat 0; PHPUnit 912/13.058; self-test 501.
Gate: `composer mutation` kini `--min-msi=71 --min-covered-msi=76`.
