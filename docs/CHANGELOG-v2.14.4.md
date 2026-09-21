# ZEF Framework — CHANGELOG v2.14.4

**Tema: Edge-Case Matrix ronde 3 — fase 3 (Runtime lifecycle).** Lanjutan langsung
fase 2b (v2.14.3): kurikulum adversarial `docs/EDGE-CASE-MATRIX.md` Tier 3 tuntas
dieksekusi. Zona sasaran adalah chunk `adapters-runtime-sec` — zona dengan MSI
terendah pada baseline ronde 3 (53.3/57.5): `Adapters/Runtime/RoadRunnerRuntime.php`
(166 escape), `Security/AuthenticationMiddleware.php` (23), `Security/SecurityRuntimeMiddleware.php`
(19), `Runtime/RoadRunnerWorkerAdapter.php` (15), `Runtime/TinkerSession.php` (11),
`Runtime/BlockingSleeper.php` (9) — total 204 escape + 38 not-covered.

## Hasil terukur (chunk adapters-runtime-sec, threads=2, Infection PCOV)

| Metrik | Sebelum | Sesudah | Δ |
|---|---:|---:|---:|
| MSI (chunk) | 53% | **80%** | +27 |
| Covered MSI | 57% | **83%** | +26 |
| Escaped mutants | 204 | **81** | −123 |
| Not-covered mutants | 38 | **22** | −16 |
| Mutan tewas tambahan | — | **+138** | — |
| Waktu rerun chunk | 2m20s | 4m43s | (test sinyal ber-poll) |

Estimasi dampak global: +~1.5 poin MSI pada baseline 8.907 mutan. Gate mutasi
naik **68/73 → 69/74**. Total akumulasi ronde 3 (fase 1 + 2 + 2b + 3):
+434 kill, escape global turun dari ~2.267 ke ~2.144.

## Test baru

**`tests/Unit/EdgeMatrixRuntimeTest.php` — 31 test / 117 asersi.** RoadRunnerRuntime,
RoadRunnerWorkerAdapter, TinkerSession, InMemoryWorker, BlockingSleeper:

- **Sinyal aman-deterministik**: pola fail-safe baru — handler diverifikasi via
  `pcntl_signal_get_handler()` SEBELUM sinyal dikirim ke proses sendiri, sehingga
  mutan yang merusak instalasi handler gagal lewat assertion bersih, bukan dengan
  mematikan proses uji (exit 143). Test SIGTERM/SIGINT/SIGCHLD yang sebelumnya
  *skip* di bawah Infection kini berjalan penuh — membuka ±20 mutan siklus sinyal
  (instalasi, kepemilikan, drain graceful, restore SIG_DFL).
- **Saturasi sumber daya**: engineering rasio memori nyata (`memory_get_usage(true)`
  vs limit terukur) dengan margin lebar yang tahan derau arena MM — mematikan
  keluarga concat/merge pada `recordResource` (validasi body log via snapshot
  in-loop hook `onRespond`, karena `Application::shutdown()` membersihkan logs),
  seri meter persis `zef.runtime.resource.events.total|{"event.name":"saturated"}`,
  jumlah emisi per-iterasi yang eksak, dan rentang atribut `memory.percent`
  (pembunuh Division/Multiplication/ceil).
- **Mesin status lifecycle**: stop() sebelum run diterima diam-diam vs stop()
  pasca-shutdown ditolak `Illegal runtime lifecycle transition stopped -> draining.`
  (fail-closed terkunci); peristiwa meter worker.started/ready/terminated/recovery
  di-assert eksak (termasuk perilaku bucket `other` untuk event di luar allowlist
  meter); flush telemetry = 1 per request + 1 saat shutdown (telemetry ENABLED
  via env, dipulihkan di finally).
- **Boundary kapasitas**: env `ZEF_RUNTIME_RESOURCE_CAPACITY=0` di-clamp ke
  minimum 1 (admission vs 503); default saat env absen teruji admissinya.
- **Failure pipeline**: error channel melempar → `error_log` menerima kegagalan
  ASLI; respond melempar → failure kedua terlaporkan; header/answer 500 tetap
  utuh; exit code 0/1/2 dipetakan ke event yang tepat.
- Worker adapter: kontrak waitRequest/respond, preferensi direct-stop (inner
  worker TIDAK ikut di-stop), fallback getWorker, ketahanan inner non-objek,
  normalisasi payload bukan-PSR-7, mirror isStopped.
- Tinker: EXIT/Quit huruf besar, laporan error `[error] Class: message` eksak,
  wrap-code dengan whitespace ekor, batas truncation 240/243-byte (ellipsis 3 byte).
- InMemoryWorker FIFO + koleksi respons + stop; BlockingSleeper negatif tanpa
  ValueError vs durasi positif terukur.

**`tests/Unit/EdgeMatrixSecAdapterTest.php` — 18 test / 69 asersi.** AuthenticationMiddleware
+ SecurityRuntimeMiddleware:

- Grammar Bearer 4 varian (`^` anchor, flag `i`, token whitespace-only, kanonik)
  via provider-spy; batas token 128/129 byte eksak.
- Truncation operationClass 128 byte dengan input non-uniform (membunuh off-by-one
  offset substr yang tak terlihat pada input uniform); replay id 129→128 byte
  non-uniform; deny 401 vs 403 berdasarkan konteks; correlation_id fallback
  16-hex eksak.
- SecurityRuntimeMiddleware: metode lowercase vs daftar aman; batas charset/panjang
  X-Request-ID (128 echo, 129 regenerate); precedence atribut trusted-proxies
  atas daftar konstruktor (rightmost-untrusted XFF); flag secure https; header-set
  lengkap pada 503/429/403-origin/403-CSRF (Retry-After, X-RateLimit-*, X-Request-ID);
  gerbang CSRF link-by-link (cookie hilang, header hilang, cookie palsu, mismatch);
  trim nama/nilai cookie ber-spasi.

## Perbaikan lingkungan uji (dua akar fatal ditemukan & diakari)

1. **Signal self-kill race (exit 143)**: pengiriman sinyal berulang setelah poll
   habis dapat meninggalkan sinyal pending kedua yang menyala SETELAH runtime
   me-restore SIG_DFL — membunuh seluruh proses PHPUnit di tengah chunk Infection.
   Solusi: sinyal dikirim TEPAT SATU KALI per worker (`$signalSent`), poll hingga
   5 detik, tanpa re-send.
2. **Routing error_log**: `ini_restore('error_log')` mengembalikan ke nilai master
   php.ini (stderr) — bukan nilai `<ini>` config Infection (`/dev/null`). Byte
   STDERR pertama membuat Infection men-SIGTERM-kan suite awal. Semua redirect
   error_log kini memulihkan via `ini_set()` ke nilai yang di-capture sebelumnya.
   (Komentar pada config warisan telah memperingatkan perilaku ini.)
3. **Kebocoran env laten (pre-existing)**: `FinalPushTest` menyetel
   `ZEF_OTEL_ENABLED=1` tanpa membersihkannya — membuat `LongTailTest` gagal
   secara flaky pada urutan acak. tearDown kini meng-unset.
4. `infection.json5` timeout 30 → 90 (suite tumbuh 748 → 797 test).

## Ekuivalen-mutant terinventarisasi (jujur, tidak dipalsukan)

Sisa 81 escape ditriase satu per satu; pola utama yang TERDOKUMENTASI (bukan
kegagalan test):

- **Branch penolakan admission mati secara struktural**: pada loop sinkron,
  `inFlight` selalu 0 saat `admitRequest()` — guard `inFlight >= capacity` tak
  pernah menolak (termasuk keluarga counter rejected/admitted yang tak terbaca
  publik). L112/L114/L250-258.
- **Counter privat tak pernah dibaca**: `resourceCounters` (completed/rejected/
  saturation) hanya ditulis; mutan ±1 tak teramati. L140/L252-253/L271.
- **Control-plane self-check tidak pernah gagal**: `validateControlCommand`
  dipanggil dengan perintah hardcoded yang selalu ada di allowlist — guard
  fail-closed L218-223/L305-306 taktis tak terjangkau (CastBool/LogicalNot/
  Throw_ ekuivalen).
- **Derau arena memori vs margin 1%**: mutan ±1 pada default/min/max
  `ZEF_RUNTIME_SATURATION_PERCENT` membutuhkan rasio rasio-memori presisi <1%,
  sementara `memory_get_usage(true)` melompat ±2.8% per arena — tak dapat
  dites deterministik (L190 ×6). Rounding `floor()` vs `round()` jatuh pada
  selisih di bawah derau (L272).
- **Log dikonsumsi sebelum teramati**: `recordLifecycle`/`recordResource` concat
  sisi LOG (bukan meter) tak teramati setelah `Application::shutdown()` membersihkan
  buffer — kecuali saat dalam-loop snapshot (yang dipakai untuk membunuh sisi
  yang mungkin). Sisa L326 dua mutan.
- **Guard platform-invariant**: `function_exists('pcntl_*')` selalu true di
  runtime uji; varian LogicalOr yang butuh fungsi absen ekuivalen. L354/L387.
- **Tinker dead catch**: `var_export()` tidak melempar untuk nilai apa pun yang
  bisa dihasilkan `eval` (sirkular → warning + null, terverifikasi empiris) —
  catch `'<unprintable:>'` tak terjangkau. L121-122 ×5.
- **Redundansi guard**: `rtrim("\r\n")` didominasi `trim()` berikutnya (Tinker L45);
  pre-check dominan atas post-check memori (L142 `>=`/AllSub); `random_bytes`
  ±1 pada ID privat (L206-207); `UnwrapFinally` pada blok efek-samping-tak-teramati.

## Verifikasi

| Tool | Hasil |
|---|---|
| PHPUnit | **797 test / 12.653 asersi** (+49 / +190), 13 skip kondisional |
| PHPStan max + strict-rules | 0 error |
| PHPCS (Slevomat) | 0 violation |
| cs-fixer (PER-CS2.0 + @PHP84Migration + risky) | 0 diff |
| Rector (agresif) | 0 diff |
| Infection chunk runtime-sec | MSI **80%** / covered **83%** (gate PASSED) |
| Gate mutasi (composer mutation) | 69/74 |

Delapan tool regresi lainnya (validate/audit/lint/self-test/deptrac/format/bench/docs)
dijalankan penuh pada tahap rilis — lihat README blok verifikasi.
