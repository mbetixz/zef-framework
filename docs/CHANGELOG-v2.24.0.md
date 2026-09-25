# ZEF Framework — CHANGELOG v2.24.0

**Redis Distributed Lock (issue #68)** — roadmap v2.24.0 terimplementasi
penuh: adapter lock Redis di atas port `LockStoreInterface` yang sudah ada
sejak v2.8.0, komponen leader election, scheduler cluster-safe, dan claim
idempotent lintas node untuk worker. Semua additive dan opt-in; tanpa
lock store yang di-inject, seluruh komponen berperilaku identik dengan
v2.23.0.

## Ringkasan

- **`RedisLockStore` (adapter, `src/Infrastructure/Cache/`)** —
  implementasi `LockStoreInterface` atas phpredis dengan semantik
  single-instance kanonik:
  - `acquire()` = `SET key owner PX ttl NX` yang dieksekusi **di dalam
    satu Lua script** (cek-keberadaan + tulis satu langkah atomik di
    server); re-akuisisi oleh owner yang sama memperpanjang lease dan
    mengembalikan true (semantik identik `InMemoryLockStore`), owner
    lain mengembalikan false;
  - `release()` = Lua compare-and-delete: hanya pemilik token saat ini
    yang boleh menghapus — node tidak pernah melepas lease yang sudah
    lapse dan diambil alih node lain;
  - `refresh()` = Lua compare-and-PEXPIRE (hanya owner yang memperpanjang);
  - `holder()` = `GET`;
  - TTL **dipaksakan oleh server Redis itu sendiri** — tidak ada jam
    lokal di jalur kritis, sehingga deployment multi-node bebas drift;
  - key di-namespacE `zef:lock:` + hash sha256 (konvensi yang sama dengan
    `RedisSharedRateLimitStore`) sehingga byte mentah key user tidak
    pernah sampai ke keyspace Redis;
  - validasi input identik port (key/owner 1..256 byte, TTL 1..86400 s).

- **`LeaderElector` (application, `src/Application/Cache/`)** — lease
  kepemimpinan bernama di atas `LockStoreInterface` mana pun (Redis di
  produksi, in-memory di test):
  - N replica berlomba lewat `acquireLeadership()`; lock store
    menjamin tepat satu pemegang lease pada satu waktu;
  - kepemimpinan *time-bounded*: pemimpin wajib memanggil
    `renewLeadership()` dalam jendela TTL (aturan praktis: perbarui
    tiap TTL/2) atau lease lapse dan replica lain mengambil alih —
    pemulihan dari leader yang crash terjadi otomatis tanpa tindakan
    operator;
  - `resign()` untuk handover terencana (rolling restart);
  - identitas kontender default `node-<hostname>-<pid>-<rand>`
    (dua proses di host yang sama tetap dua kontender berbeda),
    injeksi identitas eksplisit tersedia untuk test/config;
  - lock store adalah satu-satunya sumber kebenaran: `isLeader()` dan
    `holder()` bertanya ke store setiap panggilan, lease yang expired
    atau dicuri langsung terlihat.

- **`Scheduler` (perubahan kecil, backward-compatible)** — parameter
  constructor opsional baru `?LockStoreInterface $clusterLockStore = null`
  + `string $clusterName = 'default'` + `int $clusterTtlSeconds = 30`:
  - mode cluster: setiap `tick()` lebih dulu meng-akuisisi lease
    `zef:scheduler:<name>`; node yang tidak memegang lease **melewati
    tick sepenuhnya** (return 0) alih-alih men-enqueue dobel — job yang
    due tetap due dan tick milik pemegang lease yang meng-enqueue-nya
    tepat sekali;
  - lease **sticky dan tidak pernah di-release** setelah tick: selama
    node pelaku terus men-tick dalam TTL, lease-nya ter-refresh; saat
    ia berhenti (crash/pause), lease lapse setelah `clusterTtlSeconds`
    dan node lain mengambil alih — perilaku leader-election standar;
  - TTL wajib melampaui durasi tick terburuk (dijamin validasi 1..86400
    + dokumentasi trade-off); `relinquishClusterLeadership()` untuk
    handover graceful, `isClusterLeader()` dan `clusterOwner()` untuk
    observabilitas;
  - tanpa lock store: seluruh perilaku v2.23.0 identik (single-node
    selalu "leader").

- **`LockingJobIdempotencyStore` (application,
  `src/Application/Job/`)** — adapter `JobIdempotencyStoreInterface`
  berbasis lease untuk jaminan **exactly-once per key dalam window TTL**
  lintas node:
  - `remember()` meng-akuisisi lease `zef:jobidem:<key>` sepanjang
    window; yang menang menjalankan producer dan mengembalikan hasilnya;
  - yang kalah (key sudah diklaim/dieksekusi node lain dalam window)
    mengembalikan `null` **tanpa mengeksekusi** producer — pemanggil
    mengamati "sudah ditangani di tempat lain";
  - token owner dibuat **segar per panggilan**: re-akuisisi tidak pernah
    dianggap refresh lease oleh store, sehingga pengulangan pemanggilan
    dalam window (termasuk dari proses yang sama) selalu kalah;
  - setelah run sukses lease sengaja ditahan sampai TTL lapse:
    delivery duplikat dalam window short-circuit ke null;
  - saat producer melempar, lease di-release best-effort dan exception
    diteruskan — retry policy worker tetap sah mengeksekusi ulang,
    bukan setiap retry runtuh ke null;
  - window = TTL lease (default 3600 s); window > 1 hari ditolak di muka
    (batas atas port 86400 s).

- **`ZefVersion`** — 2.23.0 → 2.24.0.

## Non-tujuan

- **Redlock multi-node quorum** — cakupan rilis ini adalah single Redis
  instance; generalisasi quorum menyusul kalau ada deployment yang
  membutuhkannya.
- **Fencing token monoton** — mengikuti Redlock; dibuat issue terpisah
  begitu ada konsumen yang benar-benar membutuhkan pengurutan.
- **Transport queue Redis** (queue shared antar node) — milik trek
  async-runtime v2.25+; rilis ini membuat sisi konsumennya aman.

## Testing

- `RedisLockStoreTest` — 19 test / 49 asersi terhadap **server Redis
  nyata** (port 6399, requirepass, flushDB per test, skip saat tak
  terjangkau): akuisisi mutual-exclusion, re-akuisisi owner (refresh
  lease), release/refresh hanya oleh owner, expiry TTL nyata
  (`usleep` melewati jendela PX), nilai yang di-tamper menolak
  release/refresh, isolasi antar key, dan matriks validasi input.
- `LeaderElectorTest` — 17 test / 50 asersi deterministik (in-memory
  store + clock injeksi): race dua replica, perpanjangan lease vs
  lapse TTL, resign oleh non-leader tidak menghapus lease leader baru,
  isolasi antar nama eleksi, pola identitas default, validasi
  konfigurasi.
- `SchedulerClusterSafetyTest` — 13 test / 79 asersi: mode single-node
  tak berubah, follower skip (tidak dobel-enqueue), kepemimpinan
  sticky selama men-tick dalam TTL, leader lapse diambil alih otomatis,
  handover graceful, isolasi antar nama cluster, token owner unik,
  validasi konfigurasi, dan job due milik follower tetap ter-enqueue
  oleh leader tepat sekali.
- `LockingJobIdempotencyStoreTest` — 13 test / 27 asersi: producer jalan
  sekali cluster-wide, pengulangan proses yang sama pun kalah,
  kegagalan me-release lease untuk retry (dua node bergantian),
  window lapse, isolasi key, dan komposisi dengan bentuk pemanggilan
  `InProcessJobWorker::execute()`.

## Interaksi quality gate

- Deptrac: `RedisLockStore` (Infrastructure → Domain port),
  `LeaderElector` (Application → Domain), `Scheduler` kini bergantung
  `Domain/Cache/LockStoreInterface` (Application → Domain, diizinkan),
  `LockingJobIdempotencyStore` (Application → Domain). Tidak ada arah
  layer baru.
- PHPStan level max + strict rules: hasil Lua `eval()` diverifikasi
  `is_int` sebelum dibandingkan (pola `RedisSharedRateLimitStore`).
- Tidak ada permukaan SAST baru: tanpa `unserialize`, `unlink`, atau
  evaluasi dinamis; skrip Lua adalah konstanta kelas statis.
