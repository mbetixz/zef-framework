# ZEF Framework — CHANGELOG v2.25.0

## Rate Limiting: algoritma lanjutan, tiering, header standar

Rilis ini melengkapi rate limiter warisan (fixed-window per-IP global,
v2.7.0) dengan mesin rate limiting modern yang siap produksi: dua algoritma
baru (sliding window counter dan token bucket dengan refill kontinu),
evaluasi multi-tier ("most restrictive wins"), konsumsi berbobot (cost),
rantai identitas multi-sumber, dan header `RateLimit-*` (draft
IETF httpapi-ratelimit-headers) berdampingan dengan legacy `X-RateLimit-*`.

Semua additive terhadap API v2.23.0; `ZefVersion::VERSION = '2.25.0'`.

## Ringkasan

- **Domain (`src/Domain/Security/`)** — lima kontrak baru, murni
  transport-agnostic (tanpa tipe PSR-7):
  - `RateLimitAlgorithm` — enum string-backed (`fixed` / `sliding` /
    `token`) sebagai label strategi untuk konfigurasi dan diagnostik;
    `fromString()` fail-fast untuk nama tak dikenal.
  - `RateLimitRule` — VO tier: `name`, `limit`, `windowSeconds`, `cost`
    (beban satu hit), `pathPrefix`, `methods`. Validasi eager di konstruktor
    (1 <= cost <= limit; nama bebas `>` yang dipakai sebagai pemisah kunci
    penyimpanan; prefix wajib diawali `/`; methods berupa token HTTP
    uppercase) plus `fromArray()` dengan penolakan kunci tidak dikenal —
    tier salah ketik gagal di boot, bukan diam-diam diabaikan.
  - `RateLimitRuleOutcome` — hasil evaluasi satu rule (allowed, limit,
    remaining, retryAfter, resetAfter) sehingga penolakan selalu bisa
    dijelaskan per tier.
  - `RateLimitVerdict` — agregasi "most restrictive wins": allowed = semua
    rule lolos; limit = kuota paling ketat; remaining = sisa terkecil;
    retryAfter = tunggu terlama di antara rule yang menolak; resetAfter =
    jendela terlama.
  - `CostAwareRateLimiterInterface extends RateLimiterInterface` — port
    `consume(key, limit, window, cost)`: satu panggilan dapat menarik lebih
    dari satu unit kuota (laporan mahal vs pembacaan murah), atomic per
    panggilan. `RateLimitDecision` mendapat field opsional `resetAfter`
    (default 0 — empat argumen posisi lama tetap valid, backward compatible).

- **Algoritma (`src/Application/Security/`)**:
  - `SlidingWindowRateLimiter` — sliding window counter dua-bucket:
    pemakaian efektif = ceil(prev x (1 - elapsedRatio) + curr), menghaluskan
    ledakan di batas jendela tanpa log per-request (state O(1) per kunci).
    Kontrak kunci/jendela dijaga ketat: satu kunci dengan ukuran jendela
    berbeda melempar InvalidArgumentException (deteksi salah kawat), sementara
    perubahan limit (retune kuota hidup) tetap aman.
  - `TokenBucketRateLimiter` — bucket penuh sebesar limit di awal, refill
    kontinu limit/window per detik via jam nanodetik; burst legit tidak
    lagi ditolak di awal jendela. Denial tidak menggeser anchor refill.
    Bucket yang penuh dan menganggur satu jendela penuh dapat dikumpulkan GC
    (proyeksi level, bukan nilai tersimpan), maxKeys tetap menjadi batas.
  - Keduanya mengimplementasikan `CostAwareRateLimiterInterface`, menerima
    `CacheClockInterface` (jam palsu untuk uji deterministik; produksi
    memakai `HrTimeClock`, hrtime monotonik) dan meniru guard operasional
    `InMemoryRateLimiter` (validasi fail-fast, sweep GC, kapasitas maxKeys).
  - `TieredRateLimiter` — mengevaluasi sekumpulan `RateLimitRule` terhadap
    satu limiter dasar dengan kunci komposit `name>identity` (bebas tabrakan
    antar-tier maupun antar-identitas), lalu mengagregasi ke
    `RateLimitVerdict`. Rule ber-cost > 1 terhadap limiter yang tidak
    cost-aware gagal cepat dengan pesan yang menyebut nama rule — tidak
    pernah ada penarikan senyap cost 1 yang melemahkan kuota.

- **Adapter HTTP (`src/Adapters/Security/RateLimitMiddleware.php`)** —
  middleware PSR-15 mandiri:
  - Kecocokan rule: prefix path pada batas segmen (`/api` cocok `/api` dan
    `/api/users`, tidak `/apiv2`) + filter method opsional; request yang tak
    dicakup rule lolos tanpa header rate limit.
  - Rantai identitas (sumber berprefix agar tidak pernah bertabrakan dalam
    satu bucket): atribut `zef.auth.identity` (dari middleware auth) >
    header API key (default `X-API-Key`) > IP klien sadar-proxy
    (`ClientAddressResolver` + override atribut `__zef_trusted_proxies`).
    Nilai kredensial di-fingerprint sha256 (32 hex) — kunci berbatas dan
    tidak ada kredensial yang bocor ke penyimpanan.
  - Header: `RateLimit-Limit` / `RateLimit-Remaining` / `RateLimit-Reset`
    (draft IETF) + `X-RateLimit-Limit` / `X-RateLimit-Remaining` (legacy)
    pada setiap keputusan; `Retry-After` pada 429. Verdict diekspos ke
    handler via atribut `zef.security.rate_limit`.
  - Kebijakan kegagalan penyimpanan: fail-CLOSED default (503 +
    `Retry-After: 1`, setara SecurityRuntimeMiddleware) atau fail-OPEN
    (`failOpen: true`, request lanjut tanpa header rate limit).
  - Komposisi: middleware ini INDEPENDEN dari limiter global
    SecurityRuntimeMiddleware. Bila keduanya aktif, cap global (luar) dan
    cap per-tier (dalam) bertumpuk dengan sengaja — jaring pengaman global
    ditambah kuota rute/tenant.

- **Wiring (`src/Middleware/ConfigProvider.php`)** — layanan
  `middleware.security.rate_limit` selalu terdaftar, tetapi hanya masuk
  pipeline (tepat setelah `middleware.security.runtime`) bila tier
  dikonfigurasi:
  - `ZEF_SECURITY_RATE_LIMIT_TIERS` — daftar JSON objek tier
    (`[{"name":"api","limit":100,"windowSeconds":60,"pathPrefix":"/api",
    "cost":1,"methods":["POST"]}, ...]`); JSON rusak / bukan list / entry
    bukan objek / tier invalid = kegagalan BOOT fail-fast.
  - `ZEF_SECURITY_RATE_LIMIT_ALGORITHM` — `sliding` (default) atau `token`.
  - `ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN` — default `false` (fail-closed).

## Non-tujuan ( eksplisit )

- Store terdistribusi untuk algoritma baru (Redis/APCu cost-aware) — tier
  v2.25.0 berjalan per-proses; global cap terdistribusi tetap lewat
  `ZEF_RATE_LIMIT_STORE` pada SecurityRuntimeMiddleware. Ketiganya dapat
  dipakai bersama.
- Kuota per-tenant berbasis kontrak komersial (billing-grade) — tiering
  v2.25.0 adalah mekanisme teknis, bukan metering tagihan.
- `RateLimit-Reset` presisi tinggi untuk sliding window mengikuti batas
  jendela (bukan ETA ketersediaan unit tertentu seperti token bucket).

## Mutasi

Zona baru `app-rate-limit` (10 file: 5 Domain + 4 Application + 1 Adapter)
terdaftar pada ratchet `docs/mutation/` dengan **MSI 95.42%** (375/393
mutan, coverage 100%, status OK di atas floor 95). Enam belas mutan lolos
yang tersisa adalah kelas ekuivalen yang terdokumentasi: urutan prefix
pada kunci penyimpanan opaque (`identity:`/`apikey:`/`ip:`), penskalaan
konstanta nanodetik ±1 (self-consistent, tak teramati melalui `ceil`),
perbedaan PHP int/float yang tak berdampak, dan inisialisasi agregator
yang terserap `max()`; dua entri not-covered adalah guard tipe defensif
`withHeader` yang tak terjangkau oleh kontrak PSR-7.

Analisis mutasi juga memicu dua penyederhanaan desain (mutant-killing via
penghapusan kode mati): sweep token bucket kini memakai kriteria idle
saja (proyeksi level terbukti tersubsumsi oleh syarat idle ≥ 1 jendela),
dan klem `max()` yang invariant-nya telah dibuktikan dihapus dari jalur
matematika sliding window, token bucket, dan header middleware.
