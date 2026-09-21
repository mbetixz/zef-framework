# ZEF Framework v2.14.7 — Edge-Case Matrix fase 5 (app-observability)

Ronde 3 deep-dive berlanjut ke zona **`src/Application/Observability`** — 13 kelas
(telemetry pipeline penuh: Tracer/Span/processor/exporter, CounterMeter, sanitizer,
propagator W3C, health aggregator, logger). Kurikulum dibangun dari baseline mutan
riil (Telemetry: 32 escape, 4m26s) + audit adversarial per kelas.

Catatan lingkungan: sandbox reset menghapus PHP lokal — pulih via
`scripts/setup_php_env.sh` (deb php8.4.24+pcov) + ekstraksi ZIP v2.14.6 (vendor,
binary rr, build artifacts) — bukti nilai distribusi penuh.

## Hasil terukur (Infection PCOV, threads=2, --only-covered)

| Zona | MSI | Escape |
|---|---:|---:|
| Telemetry (pipeline & env config) | 83 → **85** | 32 → **30** |
| TelemetrySanitizer + Logger + Clock | 85 → **95** | 15 → **5** |
| CounterMeter + Span + NoopSpan + Tracer + InMemorySpanExporter | 94 → **98** | 7 → **2** |
| BatchSpanProcessor + HealthAggregator + TraceContext/Correlation Propagator | 80 → **86** | 42 → **32** |

Gate mutasi `composer.json` naik 71/76 → **71.5/76** (estimasi global konservatif
dari delta kill terukur; full-run gate 12 chunk tetap pekerjaan ronde akhir).
PHPUnit kini **965+ test / 13.3k+ asersi** (+63 test zona observability).

## Kurikulum yang dieksekusi (tests/Unit/EdgeMatrixObservabilityTest.php — 69 test)

- **Telemetry**: batas env strict yang DIPAKAI (timeout/queue/batch: in-range
  diterima, ±1 di luar → throw; config read-and-discard RETRY_*/DRAIN tetap
  divalidasi strict — membunuh min/max, default ±1 triaged ekuivalen); grammar
  endpoint (scheme, kredensial user-only/pass-only, query `:` legal); fallback
  InMemory tanpa endpoint; guard recordLog tiga cabang (disabled/shutdown/256
  persis); drain pipeline: `continue` menguras queue metric/log tanpa exporter
  (refleksi-inject), metric throw → `return` sebelum log (urutan panggilan
  diukur via spy), log tetap diekspor walau metric queue kosong; shutdown
  idempoten + meter events flush/shutdown eksak value 1 + dual/different
  exporter shutdown 1×/2×.
- **TelemetrySanitizer**: boundary limit (0/-5 kosong — payload panjang untuk
  membunuh substr negatif; 1..3 tanpa ellipsis; 4 = persis 4 byte; multibyte
  `ä`); default 2048 persis (len 2048 utuh, len 5000 → 2048 byte); scrub
  kontrol-char mempertahankan tab/LF; invalid UTF-8 scrub tanpa throw;
  grammar isSensitiveKey (normalisasi `_`/spasi → `-`, substring match, 17
  kasus positif / 6 negatif); redaksi (separator `=` literal, kapitalisasi
  Bearer, redaksi-dulu-baru-trunc); value (NAN→'NAN', INF/-INF, slice 32
  preserve keys, rekursif + multi-sensitive continue vs break, object →
  debug type); attributes men-drop key sensitif.
- **CounterMeter**: delta negatif/NaN/INF ditolak, float finite diterima;
  default delta 1; overflow int → clamp PHP_INT_MAX + sum float; histogram
  observe negatif legal; key ksort order-insensitive + JSON unescaped
  (unicode+slash literal); cardinality 1024 → bucket overflow PER-NAME +
  eviction first-key + guard isset tidak evict ulang; normalisasi zef.http.*
  whitelist, errors.total exception.type trunc 128 (125+…), lifecycle
  allowlist → 'other', container service.id bounded (96/[other]) — guard
  konjungtif (name && key) dibunuh via kasus name-lain dengan key terlarang.
- **Span/Tracer/NoopSpan**: lifecycle guard (sensitive/empty key, post-end
  inert semua mutator), status grammar + clamp endNs=max(start,given),
  duration & endUnixNano eksak pada SpanData, desc trunc 1024; Tracer
  disabled → NoopSpan singleton (identik `===`), parent traceId inherit,
  spanId 16 hex, end→BSP→InMemory via flush.
- **BatchSpanProcessor**: ctor guards + default 2048/256 refleksi; onEnd
  post-shutdown & queue penuh ditolak; flush batch-size eksak (2+2+1); retry
  transient sukses di panggilan ke-3 vs InvalidArgumentException break tanpa
  retry; flush queue-kosong tidak menyentuh retry policy (env invalid aman);
  flush post-shutdown dengan queue zombie ditahan guard `||`; shutdown drain
  3 attempt (deadline 500ms) + exporter shutdown 1× + urutan queue
  [s1,s2,s3] (membunuh array_splice(-1)).
- **TraceContextPropagator**: grammar `00-32hex-16hex-2hex` (case-insensitive
  → lowercase, sampled bit, zero-id, flag di luar bit-0, anchor caret/dollar
  dengan substring-of-by-leader/trailer), traceState passthrough, inject
  roundtrip.
- **CorrelationPropagator**: null/56-byte/54-byte/flag-invalid/zero-id → null;
  traceState 512 grammar-valid diterima vs 513 ditolak; uppercase hex →
  dinormalisasi; inject dual + disabled().
- **HealthAggregator**: ok/degraded containment (exception probe →
  `probe failure: <class>`), sanitasi nama (non-`[A-Za-z0-9._-]` → `_`,
  trunc 64, kosong → unnamed), message trunc 256, truncation class-name
  128 byte persis (fixture class bernama 145 char), toJson raw
  UNESCAPED_SLASHES+UNICODE (message `x/y café` literal).
- **TelemetryLogger**: mapping info/warning/error → PSR method + severity
  INFO/WARN/ERROR ke Telemetry; context sensitive → [REDACTED], object
  non-Stringable → class, Stringable → debug type; tanpa Telemetry → aman.
- **TelemetryClock**: monotonic; presisi unixNano selisih < 1.2 detik dari
  `time()×1e9` — membunuh pengali 999999999/1000000001 (offset ~1.78e9 ns).

## Triage ekuivalen yang jujur (terinventarisasi)

- Telemetry L49/52/83/84: default `registerShutdownHook`, `$logger ??=` dead
  write, hook registry `register_shutdown_function` tak terinspeksi.
- Telemetry L70-73 (8): default ±1 pada config read-and-discard (min/max-nya
  DIBUNUH lewat throw strict; hanya default yang tak teramati).
- Telemetry L153/155 (10): deadline drain ±1ms/division — timing, tanpa
  state yang dapat diamati (queue selalu terkuras).
- Telemetry L189/238: enqueue `< 1024` tak terjangkau (drain selalu
  mengosongkan) dan drainOne `&&` + reset akhir membuat perbedaan tak
  teramati.
- Sanitizer L22 (2): needle generik ('token','secret',…) men-subsume semua
  varian separator — str_replace tak mengubah hasil match.
- Sanitizer L42 (2): dua strategi scrub (mb_scrub vs convert_encoding)
  ekuivalen pada platform dengan mbstring.
- CounterMeter/Span L38cast/L122: cast (float)/(int) pada aritmetika
  yang sudah bertipe benar.
- BSP L66/67/81/83/89/90/93 (9): sleep timing, deadline boundary, for-bound
  `attempt<3` yang mendominasi break `===2`, usleep ±1.
- Correlation L29/31/33/35 (5): ctor memvalidasi ulang traceState; len==55 ==
  panjang pattern (anchor redundant); trim dead setelah guard panjang raw.
