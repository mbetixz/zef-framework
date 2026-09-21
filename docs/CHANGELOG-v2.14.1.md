# ZEF Framework — CHANGELOG v2.14.1

**Tema rilis:** Mutation deep-dive — ronde kedua. Harvest log escape penuh untuk
SELURUH layer yang belum dipetakan (17 potongan log), tiga file test deep-dive
baru (99 test / 855 assertion) yang menyerang cluster Adapters/Http, Router,
Kernel dan Application/Observability, serta kenaikan gerbang mutasi ke 64/68.

## Ringkasan

| Area | v2.14.0 | v2.14.1 |
| --- | --- | --- |
| Mutation global (estimasi dari agregat potongan terukur) | MSI 61.8% / covered 67.6% (5.504 caught dari 8.907) | **MSI ~67.0% / covered ~73.0% (~5.967 caught)** |
| Adapters/Http (escape) | 399 | **254** (RequestFactory 181 → 35, MSI file 90.9%) |
| Adapters/Router (escape) | 136 | **66** (MSI 76%) |
| Adapters/Kernel (caught) | 201 | **250** (escape 185 → 137, 45 timeout-caught) |
| Application/Observability Telemetry* | 118 caught / 158 escape | **221 caught / 74 escape (MSI 69.7%)** |
| Coverage statement (gate 90%) | 91.11% (6028/6616) | **91.55% (6075/6636)** |
| PHPUnit | 469 test / 11485 assertion | **568 test / 11830 assertion** (+5 skip kondisional ekstensi) |
| Mutation gate (`composer mutation`) | 58/62 | **64/68** |
| Versi konstanta | 2.14.0 | **2.14.1** |

\* obs-log slice: Telemetry + TelemetryLogger + TelemetrySanitizer + TelemetryClock.

## Metodologi ronde 2

- **Harvest menyeluruh**: `build/escapes-*.txt` kini mencakup 17 slice —
  seluruh Domain (core/sec/val/rest/obs/rescfg), seluruh Application
  (container/job/observability 3 slice), Infrastructure, dan seluruh Adapters
  (http/router/kernel/runtime-sec) — sehingga ronde berikutnya bekerja dari
  data escape nyata, bukan tebakan. Skrip analisis baru:
  `scripts/escape_rank.php` (ranking file/mutator) dan `scripts/escape_diff.php`
  (diff per mutan), plus `scripts/infection_chunk.sh` (sudah ada) sebagai mesin
  potongan.
- **Filter chunk dipecah lebih halus** karena Infection + PCOV melebihi batas
  waktu 10 menit per proses pada cluster besar (router+kernel, observability).

## Tiga file test deep-dive baru (tests/Unit/)

- `MutationDeepHttpTest` (55 test / 675 assertion) — superglobal fallback
  ($_COOKIE/$_GET/$_POST/$_FILES) via `fromServer` injektif, guard Content-Length
  dengan body-policy 10 byte (tepat 10 OK / 11 → PayloadTooLargeException),
  `decodeJsonBody` (posisi stream dipulihkan `tell()===2`, batas kedalaman JSON
  511 OK / 512 ditolak, kode exception 0 + previous JsonException), matriks
  skema HTTPS (on/1/off/''/0), forwarded proto/host dari proxy terpercaya
  (trim + lowercase + protokol invalid), batas SERVER_PORT (1/80/443/65535/65536/
  0/abc/int), strip front-controller (persis `/index.php`, `/index.php/`,
  `?query`, `index.phpx` TIDAK di-strip, basename mismatch), splitRequestTarget
  (absolute-form verbatim, `*`, fragment dihapus dari path tapi utuh di target,
  `http://` → Malformed REQUEST_URI), header caps via Env (hitung 128/129,
  clamp min 8, clamp max 4096, value 16384/16385 + clamp, total 65536/65537 +
  clamp — semua tepat di batas), upload (ghost downgrade, error string
  '0'/'abc'/null, size string, pohon nested, passthrough instance siap-bangun,
  penolakan entri skalar), matriks protocolVersion (9 varian), tata bahasa host
  (trim/lowercase, karakter terlarang, IPv6 + port + `:80x`, batas port 1/65535/
  65536, label DNS 63 char, host 253/254 char, `a..b`, `a-`/`-a`, underscore,
  titik ganda) dan guard konstruktor ApiVersionNegotiator.
- `MutationDeepTelemetryTest` (16 test / 92 assertion) — fromEnvironment strict
  (matriks min/max/±1 untuk 7 variabel env + non-int ditolak + BATCH_SIZE ≤
  MAX_QUEUE), endpoint OTLP (trim, matriks validasi 5 bentuk, scheme
  case-insensitive), resource snapshot via reflection, lifecycle recordLog
  (batas tepat 256, severity di-uppercase, atribut disanitasi), flush
  (snapshot meter + counter tepat 1 + atribut event), shutdown idempoten
  (flag terkunci, exporter shutdown tepat 1×, dedup saat instance METRIC dan
  LOG sama, snapshot berisi counter flush + event shutdown), kegagalan export
  ditelan, span tertunda ter-flush lewat processor saat shutdown, dan extract
  tracestate (string kosong → null).
- `MutationDeepRouterTest` (28 test / 88 assertion) — batas budget (0, tepat
  current count, +1), normalisasi + validasi method (`  get  ` → GET, token
  invalid), prioritas default tepat 0 (dua desain match berbeda), group
  (prioritas dijumlah, nested null mewarisi, rollback stack saat callback
  melempar — prefix tidak bocor, 7 bentuk atribut invalid), HEAD fallback ke
  GET, 405 memuat HEAD, constraint 400 (param/type/value), constraint failure
  pertama menang, dedup signature + parameter + nama, reverse routing trim
  dua arah, fallback (pattern `*fallback*`, 405 tetap 405), export/restore
  round-trip (constraint kustom, budget default = jumlah route, clamp 0 → 1,
  data rusak ditolak) dan ResponseEmitter (batas status 199/200/204/205/304/
  599, suppressBody, closeBody, stream tak-terbaca, chunking 20×8192+7 byte).

## Catatan teknis

- **Mutant ekuivalen tidak dipalsukan**: beberapa mutant (CastInt di belakang
  `ctype_digit`, `??=` vs `=` pada variabel segar, dsb.) tidak dapat dibedakan
  lewat API publik dan dibiarkan; sisa 254 escape Adapters/Http didokumentasikan
  di `build/escapes-adapters-http-v2.txt` untuk ronde 3.
- **Performa suite**: satu test kedalaman JSON awalnya 4.27s (assert PHPUnit
  per level) — dioptimasi ke perhitungan kedalaman polos tanpa mengurangi
  kekuatan membunuh, sehingga verifikasi Infection per-file kembali < 2 menit.
- Rector `AddClosureNeverReturnTypeRector` + cs-fixer diterapkan pada ketiga
  file baru; PHPStan max + strict-rules 0 error.

## Regresi penuh (13 tool, semuanya hijau)

validate OK · audit+policy OK (1 abandoned dipantau) · lint 356/0 · self-test
501/0 · phpunit 568/11830 (+5 skip kondisional) · phpstan max+strict 0 ·
deptrac 0 violation / 0 uncovered · format:check 0/323 · phpcs 0 ·
rector:check 0 diff · bench 0.617µs singleton / 2.236µs transient · docs doctum
OK · coverage gate 91.55% PASSED · mutation gate dinaikkan ke 64/68 berbasis
baseline terukur 67/73.

## Sisa roadmap mutation (ronde 3)

Domain (~700 escape: dom-rest 224, dom-val 148, dom-rescfg 139, dom-obs 94,
dom-sec 86), sisa Adapters/Http 254 (Uri 58, UploadedFile 46, TrustedProxy 41,
ETag 32, ApiVersionNegotiator 30, sisa RequestFactory 35), Application/
Container 188, app-rest 148, Infrastructure 248, app-job 67.
