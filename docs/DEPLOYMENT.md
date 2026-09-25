# Deployment & Operasi — ZEF Framework

Dokumen ini membahas pola produksi. Prinsip utamanya satu: **RoadRunner adalah
*persistent worker*, bukan PHP-FPM.** Setiap worker melayani banyak request di dalam
satu proses PHP yang hidup lama, sehingga asumsi "proses mati di akhir request"
tidak berlaku.

---

## 1. Topologi

```
                 ┌───────────────────────────┐
   klien  ──────▶│  RoadRunner (rerun engine)│
                 │  0.0.0.0:8080             │
                 └───────────┬───────────────┘
                             │ relay: pipes
              ┌──────────────┴──────────────┐
              ▼              ▼              ▼
        worker #1      worker #2      worker #N     (php bin/worker.php)
              │              │              │
              └──────────────┴──────────────┘
                             │
                     state eksternal
              (Redis / APCu / OTLP collector)
```

Konfigurasi ada di `.rr.yaml`:

| Kunci | Nilai | Catatan |
|-------|-------|---------|
| `version` | `"2025.1"` | versi protokol, harus cocok dengan biner `rr` |
| `server.command` | `php bin/worker.php` | perintah worker |
| `server.relay` | `pipes` | jalur komunikasi |
| `http.address` | `0.0.0.0:8080` | alamat publik |
| `http.pool.num_workers` | `4` | kalibrasi sesuai CPU nyata |
| `http.pool.max_jobs` | `0` | `0` = tanpa batas; selaras `ZEF_WORKER_MAX_JOBS` |
| `http.pool.supervisor.max_worker_memory` | `512` (MB) | 0 = nonaktif |

## 2. Titik pembekuan (*freeze points*)

Aplikasi ZEF mem-boot sekali per worker lalu membekukan (`validateAndFreeze()`) graf
container. Setelah beku:

- Definisi service, alias, dan plan kompilasi tidak dapat diubah — percobaan
  registrasi melempar `LogicException`. Ini **disengaja**: menjamin tidak ada
  service yang berubah perilaku setelah request pertama.
- Konfigurasi, rute, dan tree namespace bersifat *sealed*.
- Warm singleton disiapkan tepat sekali; objek ber-*lifetime* `SINGLETON` hidup
  lintas request.

**Konsekuensi operasional:** apa pun yang perlu berbeda per request **tidak boleh**
disimpan sebagai singleton. Gunakan `RequestScope` (lifecycle terikat request) atau
state eksternal (Redis/APCu) untuk hal yang harus dibagi antar-worker.

## 3. State lintas-request (risiko utama)

| Pola | Aman? | Catatan |
|------|-------|---------|
| Singleton *stateless* (registry, compiler, policy) | ✅ | bentuk normal |
| Singleton menyimpan request terakhir | ❌ | bocor antar pengguna |
| Cache in-memory per worker | ⚠️ | hanya konsisten per-worker; butuh L2 bersama untuk konsistensi global |
| Rate limiter in-memory | ⚠️ | batas efektif × jumlah worker |
| `InMemoryLockStore` | ⚠️ | tidak memberi mutual exclusion lintas-worker; pakai `RedisLockStore` (§3.1) |
| `Scheduler` tanpa `clusterLockStore` di banyak worker | ❌ | job terjadwal di-enqueue dobel; lihat §3.1 |

Untuk pembatas laju, pilih store bersama: `RedisRateLimiter` /
`RedisSharedRateLimitStore` (skrip Lua, atomik) atau `ApcuRateLimiter` untuk
deployment satu instance. Data akun, sesi, dan variabel per-request harus
meninggalkan proses.

### 3.1 Scheduler & job lintas node (v2.24.0)

Begitu ada lebih dari satu worker atau replika, `Scheduler` dan worker job yang
berjalan di setiap proses akan men-*tick* dan mengeksekusi secara paralel. Tanpa
koordinasi, job terjadwal di-enqueue dobel dan pesan duplikat dieksekusi berulang.
v2.24.0 menambahkan komponen opt-in di atas port `LockStoreInterface` untuk
mencegahnya. Tanpa lock store yang di-inject, perilaku identik dengan v2.23.0.

| Komponen | Namespace | Fungsi |
|----------|-----------|--------|
| `RedisLockStore` | `Zef\Framework\Cache` | implementasi `LockStoreInterface` atas phpredis; mutual exclusion lintas proses dan node |
| `LeaderElector` | `Zef\Framework\Cache` | lease kepemimpinan bernama; tepat satu replika menjadi leader |
| `Scheduler` (`clusterLockStore`) | `Zef\Framework\Job` | hanya pemegang lease yang men-*tick*; follower melewati tick |
| `LockingJobIdempotencyStore` | `Zef\Framework\Job` | eksekusi *exactly-once* per key dalam window TTL lintas node |

**`RedisLockStore`.** Ganti `InMemoryLockStore` dengan adapter ini untuk semua
deployment multi-worker/multi-replika. Semantiknya:

- `acquire()` menjalankan `SET key owner PX ttl NX` di dalam satu skrip Lua
  (atomik di server). Owner yang sama memanggil ulang → lease diperpanjang dan
  mengembalikan `true`. Owner lain → `false`.
- `release()` dan `refresh()` bersifat *compare-and-act*: hanya pemilik token saat
  ini yang boleh menghapus atau memperpanjang. Node tidak pernah melepas lease yang
  sudah lapse dan diambil alih node lain.
- TTL dipaksakan oleh server Redis, bukan jam lokal, sehingga bebas *clock drift*
  antar node.
- Key di-namespace `zef:lock:` + hash sha256. Batas input: key/owner 1..256 byte,
  TTL 1..86400 detik.

```php
use Zef\Framework\Cache\RedisLockStore;

$redis = new \Redis();
$redis->connect('redis', 6379);

$locks = new RedisLockStore($redis);
```

**Scheduler cluster-safe.** Berikan lock store ke `Scheduler` melalui parameter
constructor opsional:

```php
use Zef\Framework\Job\Scheduler;

$scheduler = new Scheduler(
    queue: $queue,
    clusterLockStore: $locks,
    clusterName: 'default',   // 1..128 byte; lease: zef:scheduler:<name>
    clusterTtlSeconds: 30,    // 1..86400; harus > durasi tick terburuk
);
```

- Setiap `tick()` lebih dulu mengakuisisi lease `zef:scheduler:<name>`. Node yang
  tidak memegang lease melewati tick sepenuhnya (return `0`). Job yang *due* tetap
  *due* dan di-enqueue tepat sekali oleh leader.
- Lease bersifat *sticky*: tidak di-release setelah tick. Selama leader terus
  men-*tick* dalam TTL, lease ter-refresh. Bila leader crash atau berhenti, lease
  lapse setelah `clusterTtlSeconds` dan node lain mengambil alih tanpa tindakan
  operator.
- Pilih `clusterTtlSeconds` lebih besar dari durasi tick terburuk. TTL terlalu kecil
  membuat lease lapse di tengah tick. TTL terlalu besar memperlambat *failover*.
- Rolling restart: panggil `relinquishClusterLeadership()` sebelum proses berhenti
  agar node lain mengambil alih tanpa menunggu TTL.
- Observabilitas: `isClusterLeader()`, `clusterOwner()`, `clusterName()`.

**`LeaderElector`.** Untuk pekerjaan singleton selain scheduler (mis. loop
pembersihan), gunakan lease kepemimpinan bernama:

```php
use Zef\Framework\Cache\LeaderElector;

$elector = new LeaderElector($locks, 'cleanup', ttlSeconds: 15);

if ($elector->acquireLeadership()) {
    // hanya satu replika yang masuk sini
    // panggil $elector->renewLeadership() minimal tiap TTL/2
}

// handover terencana (rolling restart)
$elector->resign();
```

- Identitas default `node-<hostname>-<pid>-<rand>`, sehingga dua proses di host yang
  sama tetap kontender berbeda. Parameter `identity` tersedia untuk ID node stabil.
- `isLeader()` dan `holder()` bertanya ke lock store setiap panggilan. Lease yang
  expired atau diambil alih langsung terlihat.

**`LockingJobIdempotencyStore`.** `InMemoryJobIdempotencyStore` hanya menjamin
idempotensi per proses. Untuk jaminan lintas node, inject adapter ini ke
`InProcessJobWorker`:

```php
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\LockingJobIdempotencyStore;

$worker = new InProcessJobWorker(
    queue: $queue,
    idempotency: new LockingJobIdempotencyStore($locks),
);
```

- `remember($key, $producer, $ttlSeconds = 3600)` mengakuisisi lease
  `zef:jobidem:<key>` sepanjang window. Pemenang menjalankan producer dan
  mengembalikan hasilnya.
- Node yang kalah (key sudah diklaim dalam window) mengembalikan `null` tanpa
  menjalankan producer. Pengulangan dari proses yang sama pun kalah.
- Setelah sukses, lease ditahan sampai TTL lapse sehingga *delivery* duplikat
  dalam window di-*short-circuit*.
- Bila producer melempar exception, lease di-release (best-effort) dan exception
  diteruskan, sehingga retry policy worker tetap dapat menjalankan ulang job. Bila
  release gagal (Redis tidak terjangkau), kegagalan dicatat lewat `error_log()` dan
  lease bertahan sampai TTL lapse.
- Window = TTL lease. Window lebih dari 86400 detik ditolak.

**Non-tujuan (batasan yang perlu diketahui):**

- **Tanpa Redlock / quorum multi-node.** Jaminan berlaku untuk satu instance Redis.
  Bila instance itu gagal, mutual exclusion tidak dijamin.
- **Tanpa fencing token monoton.** Node yang ter-*pause* melewati TTL dapat
  melanjutkan kerja setelah lease diambil alih node lain. Atur TTL dengan margin
  yang cukup dan buat efek samping job idempoten.
- **Tanpa transport queue Redis bersama.** Rilis ini mengamankan sisi konsumen dan
  scheduler, bukan queue-nya.

## 4. Kepemilikan sinyal & graceful shutdown

Worker tidak boleh memasang handler sinyal yang bertabrakan dengan supervisi
RoadRunner — proses induklah yang mengirim dan mengelola sinyal, lalu memberi worker
kesempatan menyelesaikan pekerjaan berjalan.

Aturan:

1. **Jangan** memasang handler `SIGTERM`/`SIGINT` sendiri pada worker.
2. Selesaikan request yang sedang berjalan sebelum keluar; jangan menutup koneksi
   ke store di tengah operasi tulis.
3. Untuk loop panjang (mis. pemrosesan job), periksa batas: `ZEF_WORKER_MAX_JOBS`
   dan `ZEF_WORKER_MEMORY_LIMIT` meminta worker mendaur ulang dirinya agar memori
   tidak tumbuh tanpa batas.
4. Hindari menutup `STDOUT`/`STDERR`; log dikirim melalui jalur tersebut.

## 5. Kesehatan & kesiapan

| Endpoint | Handler | Sifat |
|----------|---------|-------|
| `GET /health/live` | `LiveHandler` | *liveness* — proses hidup? jangan cek dependensi |
| `GET /health/ready` | `ReadyHandler` | *readiness* — siap menerima trafik? dependensi dicek |
| `GET /health` | `AggregateHealthHandler` | agregat indikator; **HTTP 503** saat *degraded* |
| `GET /metrics` | `MetricsHandler` | eksposisi Prometheus (`PrometheusRenderer`) |

Indikator kesehatan kustom diimplementasikan melalui `HealthIndicatorInterface`
(mis. `ContainerHealthIndicator`); agregasi dilakukan `HealthAggregator`.

**Kontrak probe K8s:** `livenessProbe` → `/health/live`, `readinessProbe` →
`/health/ready` (`deploy/k8s/deployment.yaml`). Jangan arahkan *liveness* ke
`/health` — ketergantungan eksternal yang sedang lambat akan memicu restart tanpa
guna, memperparah gangguan.

## 6. Observabilitas

- **Tracing OTLP** — aktif bila `ZEF_OTEL_ENABLED=1`; tanpa itu tracer bersifat
  *no-op* (`NoopSpan`), jadi tidak ada biaya saat nonaktif. Ekspor melalui
  `OtlpHttpJsonExporter` (JSON over HTTP) dan dibatch oleh `BatchSpanProcessor`.
- **Korelasi** — `CorrelationPropagator` + `TraceContextPropagator` menyebarkan
  konteks W3C (`traceparent`/`tracestate`) sehingga satu request dapat dilacak lintas
  worker.
- **Redaksi** — `TelemetrySanitizer` menyaring kunci sensitif (`authorization`,
  `api_key`, `token`, `cookie`, `secret`, …) sebelum data keluar. Ini **bukan**
  pengganti disiplin: jangan pernah menaruh rahasia di atribut span.
- **Log** — JSON terstruktur; `trace_id` ikut tercatat agar log dan trace dapat
  dikorelasikan.

## 7. Keamanan runtime

| Kontrol | Kelas | Cara mengaktifkan |
|---------|-------|-------------------|
| Header keamanan | `SecurityHeadersMiddleware` | middleware stack default |
| CORS | `CorsMiddleware` | whitelist origin eksplisit |
| CSRF | `CsrfManager` | set `ZEF_SECURITY_CSRF_SECRET` (≥ 32 byte) — kosong berarti nonaktif |
| Kebijakan asal | `OriginPolicy` | whitelist origin |
| Pembatas laju | `RateLimiter` + store | in-memory / APCu / Redis sesuai topologi |
| Perlindungan replay | `ReplayProtector` | jalur autentikasi |
| Batas body | `RequestBodyPolicy` | `ZEF_MAX_BODY_BYTES` → `413` |
| Host tepercaya | `TrustedProxyMatcher` | `ZEF_TRUSTED_HOSTS` (CSV) |
| Enkripsi | `AesGcmEncryptor` + `RotatingKeyRing` | rotasi kunci tanpa downtime |

Catatan produksi:

- **CSRF nonaktif secara default.** Mengaktifkannya adalah keputusan eksplisit:
  set secret ≥ 32 byte, dan pastikan tidak ada komponen lain yang menulis nilai itu.
- **`ZEF_TRUSTED_HOSTS` wajib disetel** untuk deployment nyata; nilai default hanya
  mencakup localhost.
- **Rate limiter in-memory tidak konsisten lintas-worker** — lihat §3.
- Rahasia hanya masuk lewat lingkungan (`EnvironmentSecretProvider`), tidak pernah
  ke berkas konfigurasi yang dikomit.

## 8. Docker

`deploy/Dockerfile` memakai *multi-stage build*: dependensi dipasang pada stage
*builder*, lalu hanya artefak yang dibutuhkan disalin ke stage *runtime* — image
akhir tidak membawa Composer maupun cache paket.

```bash
cd deploy
docker compose up --build
```

Perhatian saat menyusun image produksi:

- Jalankan sebagai pengguna **non-root**; k8s manifest sudah memakai pola ini.
- Salin `.rr.yaml` dan biner `rr` ke image; pastikan `bin/worker.php` dapat dieksekusi.
- Jangan sertakan `vendor/bin/*` yang bersifat dev (phpunit, infection, phpstan)
  pada image runtime bila tidak dipakai.
- Pasang hanya ekstensi yang dibutuhkan: `mbstring`, `dom`, `xml`, `xmlwriter`,
  plus `redis`/`apcu` bila fitur terkait dipakai.

## 9. Kubernetes

`deploy/k8s/deployment.yaml` + `deploy/k8s/service.yaml` menyediakan pola dasar:

- container non-root, resource request/limit eksplisit;
- probe memakai endpoint kesehatan ZEF (§5);
- jumlah replika ditentukan horizontal — **perhatikan**: menambah replika menambah
  jumlah worker, sehingga rate limiter in-memory kehilangan makna. Pindahkan batas
  ke store bersama sebelum melakukan *scale-out*. Hal yang sama berlaku untuk
  scheduler dan idempotensi job: aktifkan mode cluster (§3.1).
- rolling update: panggil `Scheduler::relinquishClusterLeadership()` /
  `LeaderElector::resign()` saat proses berhenti agar *failover* tidak menunggu TTL.

## 10. Runbook singkat

| Kejadian | Tindakan |
|----------|----------|
| `/health` 503 | baca indikator yang gagal pada respons agregat; cek dependensi (Redis/collector) sebelum me-restart |
| worker memori tumbuh | turunkan `ZEF_WORKER_MAX_JOBS`/`ZEF_WORKER_MEMORY_LIMIT` sehingga worker mendaur ulang lebih cepat |
| rate limit tembus | pastikan store bersama, bukan in-memory; cek jumlah worker × jumlah replika |
| job terjadwal dobel | pastikan `Scheduler` memakai `clusterLockStore` bersama (Redis) dengan `clusterName` yang sama di semua node |
| job dieksekusi ulang di node lain | pakai `LockingJobIdempotencyStore` di atas `RedisLockStore`, bukan store in-memory |
| trace hilang | verifikasi `ZEF_OTEL_ENABLED=1` dan keterjangkauan collector dari dalam pod |
| 413 beruntun | naikkan `ZEF_MAX_BODY_BYTES` **atau** perbaiki klien — jangan naikkan tanpa batas |
| deploy tidak zero-downtime | periksa urutan: readiness harus gagal lebih dulu sebelum proses lama dihentikan |
