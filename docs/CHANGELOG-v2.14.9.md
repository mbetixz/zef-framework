# ZEF Framework v2.14.9 — Edge-Case Matrix Fase 8–9 (Infrastructure & c3 Observability)

> Lanjutan kampanye mutation testing ronde 3 (gate global 75/80 → **77/82**; garis finis 85/90
> menunggu fase terakhir: Container sisa + Adapters/Kernel).

## Ringkasan

Fase 8 menuntaskan seluruh zona **Infrastructure** (Cache, Config, Security-infra,
Observability-infra); fase 9 menuntaskan **c3 = Application\Observability** (Telemetry inti,
Tracer/Span/BSP, Propagator/Health/Meter). Seluruh chunk diverifikasi dengan
`scripts/infection_chunk.sh` (foreground, `--threads=2 --no-progress`, log per chunk di
`build/infection-summary-*.json`).

| Zona (chunk)          | Sebelum        | Sesudah        | Escape                    |
|-----------------------|----------------|----------------|---------------------------|
| f8-cache              | — (baru)       | **96 / 98**    | 4 (dari 221 mutan)        |
| f8-config             | — (baru)       | **94 / 96**    | 6 (dari 199 mutan)        |
| f8-secinfra           | 53 / 56        | **85 / 89**    | 28 (semua tertriase)      |
| f8-obsinfra           | — (baru)       | **90 / 91**    | 22 (semua tertriase)      |
| f9-c3a (Telemetry ×4) | — (baru)       | **83 / 89**    | 32 (semua tertriase)      |
| f9-c3b (Tracer/Span/BSP) | — (baru)    | **82 / 82**    | 23 (semua tertriase)      |
| f9-c3c (Propagator/Health/Meter) | — (baru) | **96 / 96** | 3                     |

Suite: **1395 test / 16.242 asersi** (18 test kurikulum baru fase 8–9; skip turun 11 → 5 karena
lingkungan verifikasi kini memuat ekstensi APCu + phpredis dan server Redis lokal — test yang
dulu di-skip kini benar-benar dieksekusi).

## Peristiwa lingkungan (penting untuk reproduksi)

1. Sandbox ter-reset ke-* tujuh. PHP 8.4.24 + PCOV dipulihkan via `scripts/setup_php_env.sh`;
   **vendor penuh diekstrak dari `zef-framework-v2.14.8.zip`** — bukti kedua nilai arsip
   distribusi penuh (project hidup lagi < 2 menit tanpa `composer install`).
2. Ekstensi **php8.4-apcu / php8.4-igbinary / php8.4-redis** dipasang lokal (direktifinya
   `apc.enable_cli=1`, bukan `apcu.enable_cli`); **redis-server 8.0.2** lokal di
   `127.0.0.1:6399` (auth `zef-test-secret`, sesuai konstanta `RedisStoreTest`) via deb
   `redis-tools` + `LD_LIBRARY_PATH` — 6 test Redis/APCu berhenti di-skip.
3. Gotcha baru tercatat: **`base64_decode(..., strict=true) mengabaikan padding`** — dua mutan
   aritmetika padding di `AesGcmEncryptor::b64urlDecode()` terbukti ekuivalen lewat probe;
   dan **decode strict memproses kunci penuh (bukan substring yang match)**, sehingga junk
   pembunuh mutan anchor regex harus *panjangnya* di luar jendela {43,44}, bukan karakternya.

## Sorotan kill (1 edge test = 1 pembunuh)

### f8-secinfra — AesGcmEncryptor, RotatingKeyRing, Apcu/Redis limiter
- **Anchor regex b64 kunci (:103)**: string 46-char *semua dalam alphabet* ditolak `got 46`
  oleh original; mutan PregMatchRemoveCaret/Dollar mencocokkan substring 43–44 lalu decode
  penuh sukses (34 byte) → pesan berganti `got 34` → mati. (Junk di luar alphabet tidak
  membunuh: strict-decode tetap gagal → perilaku identik.)
- **RotatingKeyRing (:97)**: pesan kegagalan ring di-assert SAME penuh termasuk detail
  `Last error: …` — ConcatOperandRemoval prefix-only tidak lagi lolos.
- **Default `maxKeys = 10000`** (Apcu:23 + Redis:17): kontrak via `ReflectionParameter`
  — satu aserti membunuh Dec+Inc kedua kelas.
- **APCu nyata** (ekstensi terpasang): siklus window penuh (add → inc → boundary count==limit
  masih allowed → >limit ditolak → key independen → guard limit/window & limit=1 window=1),
  jendela basi pre-seed (re-store + counter reset; cek kedua membunuh FunctionCallRemoval
  re-store), window numeric-string (re-anchor path; retryAfter 30 membunuh LogicalAnd),
  boundary `stored = now+1` → retryAfter == 1 (membunuh max(1→2)).
- **RedisSharedRateLimitStore** via harness `\Redis` palsu (eval/hMGet terjadwal — `\Redis`
  tidak final): matriks sanitasi 4 kasus (kedua/0-saja/1-saja/kedua numerik) membunuh
  index-swap :47/:48, fallback 0→±1, Ternary swap, Plus now+window→now-window, cast;
  matriks peek (skalar/field hilang/non-numerik → null; valid → int murni) membunuh
  LogicalOr :58 ×3 + CastInt :66; eval skalar → RuntimeException persis (LogicalOr :44).

### f8-obsinfra — PrometheusRenderer & OtlpHttpJsonExporter
- **Dedup TYPE per-nama-metrik**: dua series nama sama + metrik kedua — swap kunci dedup
  menduplikasi TYPE (2×), removal menciderai TYPE metrik kedua → mati (Concat :65/:66 ×4).
- **Boundary eksak `PHP_FLOAT_EPSILON`**: |sum−count| == eps (0.5 vs 0.5+eps, eksak
  ter-representasi) TIDAK memicu histogram — membunuh GreaterThan → >= (:63).
- **OneZeroFloat/CastFloat (:61)**: series count-null/sum-null tanpa `_sum` + series sum
  string `'9'` → `d_sum 9.0` eksak.
- **OTLP**: asDouble wajib float riil dari sum `'12.5'` (CastFloat :64 — dengan sum int lama
  cast tak-terobservasi di JSON); scope.name `zef-observability` wajib utuh (ArrayItemRemoval
  :100/:101); default timeout 500 ms via refleksi (:21 ×2); decoy header `X-Proto: HTTP/2 599`
  membuktikan parse baris status caret-anchored (:237).
- Harness server HTTP fork in-process menerima decoy header pada responsnya.

### f9-c3 — Telemetry, TelemetryLogger, TelemetrySanitizer
- Default `$registerShutdownHook = true` via refleksi (Telemetry:49).
- `ZEF_OTEL_ENABLED=true` → tracer riil (bukan NoopSpan) — kontrak cabang enabled.
- Normalisasi spasi→hubung wajib: `isSensitiveKey('X API KEY') === true` membunuh
  UnwrapStrReplace:22 (tanpa str_replace, tidak ada jarum yang match).

## Inventaris ekuivalen (jujur, dengan justifikasi — tidak di-exclude dari config)

- **AesGcmEncryptor :54** guard enkripsi gagal tak terjangkau (kunci 32-byte valid +
  aes-256-gcm selalu sukses); **:95** fall-through mengembalikan nilai sama; **:120 ×2**
  strict-base64 mengabaikan padding (terbukti via probe).
- **ApcuRateLimiter :44 ×4** nama kunci internal (`c:`/`w:`) — namespace tetap unik;
  **:47/:50/:54/:55/:60** TTL `+60` ±1 / plus→minus = timing-equivalent (jalur recovery
  mengompensasi); **:50 FCR** `apcu_inc` auto-create mengompensasi; **:53 GreaterThan** race
  batas detik-dinding; **:67 Decrement** `max(1,·)` redundan (reset−now ≥ 1 struktural).
- **RedisSharedRateLimitStore :41 ×2** — `tonumber()` Lua identik untuk int/string.
- **OtlpHttpJsonExporter :217 ×4 / :219 ×3 / :227 / :230 / :231 / :233 / :235 / :238 / :240**
  — klaster transport jaringan: header otomatis dilengkapi wrapper http stream, timeout/usleep
  timing-equivalent, error-handler tertelan PHPUnit, `use_include_path` irrelevan untuk URL,
  restore-handler tertelan try/finally, status awal selalu tertimpa, `?->` redundan di balik
  catch(\Throwable), break setara continue pada baris status tunggal. Pola setara-CLI fase 4.
- **PrometheusRenderer :41** break ≡ continue (kondisi monotonik); **:57/:66 TrueValue** set
  dedup hanya dibaca isset; **:105/:148** render minor.
- **BatchSpanProcessor (c3b) 22 mutan** — klaster retry/shutdown: `for < 3` ≡ `<= 3`
  (break `attempt === 2` mendominasi), `usleep(50000)` ±1, deadline `>=`/`<` race jam,
  IfNegation function_exists, Break_ → continue pada kondisi monotonik, LogicalOr → &&
  hanya mengubah latensi. Span:122 `(int)` identitas pada selisih hrtime int.
- **Telemetry (c3a)** — `:52` `$logger` dead-write; `:70–:73` ×8 default tuning env
  (pasangan retry bahkan validation-only); `:83/:84` hook shutdown tak terintrospeksi;
  `:153 ×8 / :156 / :189 ×2 / :195` guard antrean 1024 — kedalaman struktural ≤ 1
  (drainDelivery array_shift selalu mengosongkan); `:162` NullSafe redundan di balik
  catch(\Throwable); `:189/:229/:238` LogicalAnd ≡ LogicalOr (operand kedua selalu benar
  saat guard tercapai). **TelemetryLogger :67 ×3** — `::class` ≡ `get_debug_type` untuk
  semua objek termasuk anonim → ternary redundan. **TelemetrySanitizer :22 ArrayItemRemoval
  ×2** — jarum ber-hyphen tersubsumsi (api_key/apikey/cookie/token/secret); **:42** mb_scrub
  no-op pada UTF-8 valid; **:43** mb_scrub tersedia di PHP 8.4 → fallback tak terjangkau.

## Rilis & kualitas

- `ZefVersion::VERSION` → **2.14.9**; gate mutasi composer 75/80 → **77/82**.
- Arsip distribusi: **`zef-framework-v2.14.9.zip`** (ZIP normal, tanpa pengecualian — vendor
  penuh + binary `rr` + build API + log mutasi). Permintaan user: setiap rilis arsip kini
  **selalu membawa nomor versi baru** (fase 7 lupa bump → diperbaiki mulai rilis ini).
- Regresi 13 tools dijalankan pasca-rilis (lihat README blok verifikasi).
