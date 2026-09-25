# ZEF Framework — CHANGELOG v2.26.0

**Async Runtime — scheduler fiber-native tanpa dependensi baru**

> PHP 8.4 · zero new runtime dependencies · hexagonal: Domain ports + Application kernel
>
> Zona mutasi baru `app-runtime-async`: **MSI 96.64%** (387 mutan, 374 terdeteksi,
> 12 escape ekuivalen terdokumentasi + 1 not-covered defensif). Coverage gate 97%+,
> PHPStan level max + strict rules tanpa penambahan baseline, Deptrac 0 pelanggaran.

## Ringkasan

v2.26.0 menambahkan runtime asinkron berbasis **native PHP Fibers** ke lapisan
Runtime yang sudah ada (RoadRunner worker, sleeper port). Runtime ini memberi
aplikasi ZEF primitif konkurensi kooperatif — coroutine, channel CSP, semaphore,
wait group, cancellation, timeout — tanpa ekstensi baru, tanpa event loop eksternal,
dan tanpa preemption: model eksekusinya deterministik, single-threaded, dan
mudah di-debug.

Desain hexagonal tetap ketat:

- **Domain** (`src/Domain/Runtime/Async/`): port + value object murni —
  `TaskInterface`, `TaskState`, `CancellationTokenInterface`,
  `CancellationTokenSource`, `CancellationToken`, `MonotonicClockInterface`,
  dan hierarki `AsyncException`.
- **Application** (`src/Application/Runtime/Async/`): kernel + primitif —
  `FiberScheduler`, `FiberTask`, `SuspensionHandle`, payload suspensi internal,
  `FiberChannel`, `Semaphore`, `WaitGroup`, `CoroutineLocal`,
  `HrMonotonicClock`.

Deptrac: Domain → Compat saja; Application → Domain. Nol edge baru keluar lapisan.

## Domain — `Zef\Framework\Runtime\Async`

- **`TaskInterface`** — handle coroutine: `id()` monotonik, `name()`
  (`task-N` / `timer-N` / label kustom), `state()`, `isDone()`, `cancel()`
  (kooperatif, idempoten), `result()` (rethrow failure/cancellation,
  LogicException untuk task belum selesai), `throwable()`.
- **`TaskState`** — enum `Pending → Running → {Succeeded, Failed, Cancelled}`
  + `isTerminal()`; transisi linear, state terminal permanen.
- **`CancellationTokenInterface` + `CancellationTokenSource` +
  `CancellationToken`** — registry pembatalan sekali-pakai: token view
  read-only (`isCancelled`, `throwIfCancelled`, `register` → closure
  unregister), `cancel()` idempoten (true sekali), callback menyala sinkron
  urut registrasi, register-dimasanya-sudah-cancel menyala seketika.
- **`MonotonicClockInterface`** — port jam nanosecond monotonik (hrtime di
  produksi); timer, deadline, dan budget timeout semuanya dihitung lewat port
  ini sehingga test bisa menggeser waktu secara deterministik.
- **Hierarki exception** (`AsyncException` base):
  - `TaskCancelledException` — muncul tepat di titik suspensi yang dibatalkan
    (blok `finally` tetap jalan).
  - `AsyncTimeoutException` — deadline menang; previous exception memuat
    pembatalan aslinya.
  - `ChannelClosedException` — kirim/terima pada channel yang mati.
  - `DeadlockException` — semua task tersuspensi tanpa apa pun yang bisa
    membangunkan (pesan menyebut nama-nama tasknya).
  - `UnobservedTaskException` — kegagalan task yang tidak pernah di-await;
    kegagalan tidak pernah ditelan diam-diam.

## Application — kernel & primitif

### `FiberScheduler` — kernel kooperatif

- `spawn(callable $fn, string $name = '')` — antre, tidak pernah mengeksekusi
  kode user secara sinkron; fiber lahir saat pump.
- `run(callable $main): int` — menggerakkan seluruh run: pump sampai semua
  task settle, lalu surface kegagalan (main dulu, lalu unobserved pertama).
  Guard reentrancy (`LogicException`), reset penuh di `finally` sehingga
  scheduler bisa dipakai ulang.
- `await(TaskInterface)` / `awaitAll(list)` — suspensi sampai settle;
  `awaitAll` menandai semua task observed di depan dan rethrow failure
  pertama tanpa membatalkan saudaranya.
- `suspend()` — yield kooperatif ke ekor antrean (round-robin FIFO adil).
- `sleep(float $seconds)` — suspensi berbasis timer monotonik; `sleep(0)`
  tetap yield satu tick lewat timer due-now.
- `timeout(float $seconds, callable $fn)` — guard timer membatalkan task
  dalam saat lewat batas; kegagalan bukan-cancellation diteruskan apa adanya;
  pembatalan si-pemanggil tetap menang atas deadline.
- **Pump**: antrean `SplQueue` FIFO + antrean timer terurut due (equal-due
  mempertahankan urutan penyisipan); idle-wait dihitung `ceil()` ke milidetik
  lewat `SleeperInterface` — test memajukan waktu lewat fake sleeper.
- **Deteksi deadlock**: antrean kosong + tanpa timer + ada task tersuspensi →
  `DeadlockException` dengan daftar nama task.
- **Surfacing kegagalan**: exception fiber ditangkap di pump (tidak pernah
  lolos keluar), task jadi `Failed`/`Cancelled`; setelah run settle, kegagalan
  yang tidak pernah di-await naik sebagai `UnobservedTaskException`.
- **Pembatalan kooperatif**: `cancel()` = permintaan; korban merasakannya di
  titik suspensi berikutnya sebagai `TaskCancelledException`. Task yang
  dibatalkan sebelum langkah pertama settle `Cancelled` tanpa dieksekusi;
  race deliver-vs-cancel diselesaikan oleh pemeriksaan flag pasca-wake.

### Primitif blocking

- **`FiberChannel`** — channel FIFO berbatas (kapasitas ≥ 1, default 1):
  sender park saat penuh, receiver park saat kosong, invarian "paling satu
  sisi yang park", direct handoff tanpa duplikasi buffer, `close()`
  idempoten (buffer bertahan sampai habis, semua yang park gagal dengan
  `ChannelClosedException`), splice antrean saat cancellation sehingga
  entry hantu tidak bisa menelan nilai.
- **`Semaphore`** — pembatas konkurensi: `tryAcquire`, `acquire` (park saat
  habis, opsional `CancellationToken`), `release` (serahkan izin langsung ke
  waiter tertua), over-release → `LogicException`.
- **`WaitGroup`** — barrier hitung: `add(delta ≥ 1)`, `done()`
  (wajib berpasangan; tanpa pasangan → `LogicException`), `await()` park
  sampai nol, semua waiter bangun bersamaan.
- **`CoroutineLocal`** — penyimpanan per-koroutine berbasis `WeakMap<Fiber>`:
  isolasi penuh antar coroutine, GC otomatis bersama fiber mati, panggilan di
  luar koroutine → `LogicException`.
- **`HrMonotonicClock`** — default produksi `hrtime(true)`.

## Penggunaan

```php
$scheduler = new FiberScheduler();

$scheduler->run(function () use ($scheduler): void {
    $channel = new FiberChannel($scheduler, 8);
    $limiter = new Semaphore($scheduler, 4);

    foreach ($urls as $url) {
        $limiter->acquire();
        $scheduler->spawn(function () use ($scheduler, $channel, $limiter, $url): void {
            try {
                $channel->send(fetch($url));
            } finally {
                $limiter->release();
            }
        });
    }

    $scheduler->timeout(5.0, function () use ($scheduler, $channel): void {
        // drain hasil sambil produk berjalan...
    });
});
```

## Mutasi & bukti kualitas

- Zona baru **`app-runtime-async`** (Domain + Application Runtime/Async):
  387 mutan, **MSI 96.64% / covered 96.89%** — di atas target 95.
- 12 escape didokumentasikan sebagai ekuivalen (idempotensi close(),
  gap-reindex `array_values`, produk float nanodetik yang eksak untuk
  round/floor/ceil, timing unregister semaphore, delivery-all wait group,
  fast-path optimistik pada await/sleep(0)).
- Test: 99 test baru (kernel 41 + primitif 58) — deterministik penuh lewat
  `FakeAsyncClock` + `FakeAsyncSleeper` (tanpa sleep nyata), mencakup urutan
  round-robin, FIFO channel, handoff vs buffer, splice cancellation, batas
  semaphore, wait group multi-waiter, CTS urutan callback, deadlock,
  unobserved failure, timeout race, dan presisi nanodetik timer.
- Refactor hasil analisis mutasi: antrean timer tanpa `seq` (tiebreak
  tak teramati), `receive()` tanpa cabang sender-handoff yang mustahil oleh
  invarian park, `step()` tanpa cabang cancel-before-start yang tak
  terjangkau, `awaitSuspension()` tanpa `disarm()` yang tak terbaca.

## Kompatibilitas

- Tidak ada perubahan API publik lama; fitur murni aditif.
- `ZefVersion::VERSION` → `2.26.0`.
- PHP ≥ 8.4 (Fiber sejak 8.1; readonly class + `#[\Override]` 8.3/8.4).
