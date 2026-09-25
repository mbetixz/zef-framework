# Async Runtime & Async Rules Engine

> Berlaku sejak v2.26.0 (runtime) dan v2.27.0 (rules engine). Dokumen ini
> menjelaskan runtime asinkron berbasis native PHP Fibers dan `AsyncRuleEngine`
> yang dibangun di atasnya. Tidak ada ekstensi atau dependensi baru.

---

## 1. Kapan dipakai

Gunakan runtime ini ketika Anda perlu menjalankan beberapa pekerjaan yang
banyak menunggu (I/O, timer, antrean) secara bersamaan di dalam satu proses
PHP. Contohnya: fan-out ke beberapa layanan, pipeline producer/consumer, atau
evaluasi sekumpulan rule dengan batas waktu.

Runtime ini **tidak** memberi paralelisme CPU. Seluruh eksekusi berjalan di
satu thread.

## 2. Model eksekusi

- **Kooperatif, non-preemptive.** Sebuah coroutine berjalan sampai ia sendiri
  mencapai titik suspensi: `await()`, `awaitAll()`, `suspend()`, `sleep()`,
  `timeout()`, atau operasi blocking pada `FiberChannel`, `Semaphore`, dan
  `WaitGroup`. Kode yang tidak pernah suspend tidak bisa diinterupsi.
- **Deterministik.** Antrean siap berupa FIFO round-robin. Timer diurutkan
  berdasarkan waktu jatuh tempo; timer dengan waktu sama mempertahankan urutan
  penyisipan.
- **Jam monotonik.** Timer, deadline, dan budget timeout dihitung lewat port
  `MonotonicClockInterface` (default `HrMonotonicClock` berbasis `hrtime`).
  Idle-wait memakai `SleeperInterface`. Keduanya bisa diganti di test agar waktu
  bisa dimajukan tanpa sleep nyata.

Namespace: `Zef\Framework\Runtime\Async`.

## 3. `FiberScheduler`

```php
use Zef\Framework\Runtime\Async\FiberScheduler;

$scheduler = new FiberScheduler(); // opsional: new FiberScheduler($clock, $sleeper)
```

| Method | Perilaku |
|--------|----------|
| `run(callable $main): int` | Driver blocking tingkat atas. Menjalankan `$main` sebagai task `main`, memompa sampai semua task settle, lalu melempar kegagalan. Mengembalikan `0` jika sukses. Memanggil `run()` saat scheduler sedang berjalan melempar `LogicException`. Scheduler di-reset setelah selesai sehingga bisa dipakai ulang. |
| `spawn(callable $fn, string $name = ''): TaskInterface` | Mengantrekan coroutine baru. Kode Anda tidak pernah dieksekusi secara sinkron; task tetap `Pending` sampai scheduler memompanya. Nama default `task-N`. |
| `delay(float $seconds, callable $fn, string $name = ''): TaskInterface` | Menjadwalkan `$fn` setelah `$seconds`. Membatalkan task sebelum jatuh tempo menghapus timernya. Nama default `timer-N`. |
| `await(TaskInterface $task): mixed` | Suspend sampai task settle, lalu mengembalikan hasilnya atau melempar ulang kegagalannya. |
| `awaitAll(array $tasks): array` | Menandai semua task sebagai *observed*, menunggu semuanya, lalu melempar ulang kegagalan pertama. Task saudara **tidak** dibatalkan. |
| `suspend(): void` | Yield ke ekor antrean siap. |
| `sleep(float $seconds): void` | Suspend berbasis timer monotonik. `sleep(0)` tetap yield satu tick. |
| `timeout(float $seconds, callable $fn): mixed` | Menjalankan `$fn` sebagai task dalam. Jika batas terlewati, task dibatalkan dan `AsyncTimeoutException` dilempar. Kegagalan lain diteruskan apa adanya. Pembatalan pemanggil tetap menang atas deadline. |

### Task

`TaskInterface` menyediakan `id()`, `name()`, `state()`, `isDone()`,
`cancel()`, `result()`, dan `throwable()`. `result()` melempar ulang kegagalan
atau pembatalan, dan melempar `LogicException` jika task belum selesai.

`TaskState` bergerak linear: `Pending → Running → {Succeeded, Failed, Cancelled}`.
State terminal bersifat permanen (`isTerminal()`).

### Contoh

```php
$scheduler->run(function () use ($scheduler): void {
    $a = $scheduler->spawn(fn () => fetchUser(1), 'user-1');
    $b = $scheduler->spawn(fn () => fetchUser(2), 'user-2');

    [$u1, $u2] = $scheduler->awaitAll([$a, $b]);

    $profile = $scheduler->timeout(2.0, fn () => fetchProfile($u1));
});
```

## 4. Pembatalan

Pembatalan bersifat **kooperatif**. `cancel()` hanya sebuah permintaan. Task
korban menerima `TaskCancelledException` di titik suspensi berikutnya, dan blok
`finally` tetap dijalankan. Task yang dibatalkan sebelum langkah pertamanya
langsung settle sebagai `Cancelled` tanpa dieksekusi. `cancel()` idempoten.

Untuk sinyal pembatalan lintas komponen, gunakan `CancellationTokenSource`:

```php
use Zef\Framework\Runtime\Async\CancellationTokenSource;

$cts = new CancellationTokenSource();
$token = $cts->token();

$unregister = $token->register(fn () => $logger->info('dibatalkan'));

// di dalam coroutine
$token->throwIfCancelled();

$cts->cancel(); // true pada panggilan pertama, false setelahnya
```

- `token()` mengembalikan view read-only: `isCancelled()`,
  `throwIfCancelled()`, dan `register(callable): \Closure`. Closure yang
  dikembalikan membatalkan registrasi.
- Callback dijalankan secara sinkron sesuai urutan registrasi.
- Registrasi pada token yang sudah dibatalkan langsung menjalankan callback.

## 5. Primitif blocking

Semua primitif menerima `FiberScheduler` di konstruktor dan hanya boleh
dipakai di dalam coroutine.

### `FiberChannel`

Channel FIFO berbatas bergaya CSP.

```php
$channel = new FiberChannel($scheduler, 8); // kapasitas >= 1, default 1

$channel->send($value);   // park saat buffer penuh
$item = $channel->receive(); // park saat buffer kosong
$channel->close();
```

- Nilai diserahkan langsung ke receiver yang sedang park jika ada.
- `close()` idempoten. Nilai yang sudah di-buffer tetap bisa diterima sampai
  habis. Pengirim dan penerima yang sedang park gagal dengan
  `ChannelClosedException`. `send()` pada channel tertutup juga melempar
  exception ini.
- Pengirim yang sedang park lalu dibatalkan kehilangan nilainya dan melempar
  `TaskCancelledException`.
- `isClosed()` dan `count()` tersedia untuk inspeksi.

### `Semaphore`

Pembatas konkurensi.

```php
$limiter = new Semaphore($scheduler, 4); // permit >= 1

$limiter->acquire($token); // park saat izin habis; token opsional
try {
    // pekerjaan terbatas
} finally {
    $limiter->release();
}
```

`tryAcquire()` mengembalikan `bool` tanpa park. `release()` menyerahkan izin
langsung ke waiter tertua. Melepas lebih banyak izin daripada yang diambil
melempar `LogicException`.

### `WaitGroup`

Barrier hitung.

```php
$wg = new WaitGroup($scheduler);

foreach ($jobs as $job) {
    $wg->add();
    $scheduler->spawn(function () use ($wg, $job): void {
        try { $job(); } finally { $wg->done(); }
    });
}

$wg->await(); // park sampai hitungan nol
```

`add()` menerima delta ≥ 1. `done()` tanpa pasangan `add()` melempar
`LogicException`. Semua waiter dibangunkan bersamaan.

### `CoroutineLocal`

Penyimpanan per-coroutine, mirip thread-local.

```php
$local = new CoroutineLocal();
$local->set('request_id', $id);
$local->get('request_id', null);
```

Setiap coroutine terisolasi penuh. Data dibersihkan otomatis bersama fiber
yang mati. Pemanggilan di luar coroutine melempar `LogicException`.

## 6. Semantik kegagalan

| Exception | Kapan muncul |
|-----------|--------------|
| `TaskCancelledException` | Di titik suspensi task yang dibatalkan. |
| `AsyncTimeoutException` | Deadline `timeout()` menang. Previous exception memuat pembatalan aslinya. |
| `ChannelClosedException` | Kirim/terima pada channel tertutup. |
| `DeadlockException` | Antrean siap kosong, tidak ada timer, tetapi masih ada task yang tersuspensi. Pesan menyebut nama task yang macet. |
| `UnobservedTaskException` | Task gagal tetapi tidak pernah di-`await`. |

Semua exception di atas turunan `AsyncException`.

Exception dari dalam fiber tidak pernah lolos dari pump. Task ditandai
`Failed` atau `Cancelled`. Setelah semua task settle, `run()` melempar
kegagalan task `main` terlebih dahulu, lalu kegagalan unobserved pertama.
Kegagalan tidak pernah ditelan diam-diam. Jadi, selalu `await()` atau
`awaitAll()` task yang Anda `spawn()`.

---

## 7. Async rules engine

`AsyncRuleEngine` (sejak v2.27.0) mengevaluasi setiap rule sebagai
coroutine-nya sendiri di atas `FiberScheduler`. Engine ini mendukung batas
konkurensi, deadline per rule, dan fail-fast. Evaluasi bersifat total: satu
verdict per rule, urut sesuai input, dan masalah level-rule tidak pernah
dilempar sebagai exception.

Namespace: `Zef\Framework\Rules`.

### `RuleInterface`

```php
use Zef\Framework\Rules\RuleInterface;
use Zef\Framework\Rules\RuleVerdict;
use Zef\Framework\Runtime\Async\CancellationTokenInterface;

final class QuotaRule implements RuleInterface
{
    public function name(): string
    {
        return 'quota';
    }

    public function evaluate(mixed $subject, CancellationTokenInterface $cancellation): RuleVerdict
    {
        if ($cancellation->isCancelled()) {
            return RuleVerdict::skip('evaluation cancelled');
        }

        return $subject->quota > 0
            ? RuleVerdict::pass()
            : RuleVerdict::fail('quota exhausted', ['quota' => $subject->quota]);
    }
}
```

Rule berjalan di dalam coroutine engine. Rule boleh suspend (`sleep`, channel,
dan sebagainya) untuk mendapat konkurensi nyata. Token cancellation adalah
sinyal kooperatif. Rule boleh memeriksanya dan kembali dengan
`RuleVerdict::skip()`. Jangan melempar `TaskCancelledException` dari rule
untuk alasan sendiri. Exception itu direservasi untuk pembatalan engine dan
akan dilaporkan sebagai timeout.

### `RuleVerdict` dan `RuleVerdictStatus`

`RuleVerdictStatus` adalah enum `Passed | Failed | Skipped`.

| Factory | Status | Keterangan |
|---------|--------|------------|
| `RuleVerdict::pass(string $message = '', array $metadata = [])` | `Passed` | Rule terpenuhi. |
| `RuleVerdict::fail(string $message, array $metadata = [])` | `Failed` | Rule dilanggar. |
| `RuleVerdict::skip(string $reason, array $metadata = [])` | `Skipped` | Rule tidak berlaku untuk subject ini. |
| `RuleVerdict::fromThrowable(\Throwable $e, array $metadata = [])` | `Failed` | Pesan `"class: message"`; throwable asli tersedia lewat `throwable()`. |
| `RuleVerdict::timeout(string $ruleName, float $seconds)` | `Failed` | Dihasilkan engine saat deadline terlewati. Metadata memuat `timeout_seconds`. |

Accessor: `status()`, `message()`, `metadata()`, `throwable()`, `ruleName()`,
`isPassed()`, `isFailed()`, `isSkipped()`. Engine mengisi `ruleName()` dari
`RuleInterface::name()`.

### `RuleReport`

| Method | Keterangan |
|--------|------------|
| `verdicts()` | Semua verdict, urut sesuai input. |
| `count()` | Jumlah verdict. |
| `allPassed()` | `false` jika ada verdict `Failed`. Verdict `Skipped` tidak memveto. Rule set kosong dianggap lolos. |
| `passed()` / `failures()` / `skipped()` | Partisi verdict per status. |

### `RuleEngineOptions`

Value object immutable dengan wither.

| Opsi | Default | Keterangan |
|------|---------|------------|
| `concurrency` | `null` (tanpa batas) | Jumlah rule maksimum yang dievaluasi bersamaan. Harus ≥ 1. |
| `perRuleTimeout` | `null` (tanpa deadline) | Deadline kooperatif per rule dalam detik. Harus ≥ 0. Rule yang melewatinya menjadi `Failed` via `RuleVerdict::timeout()`. |
| `failFast` | `false` | Verdict `Failed` pertama membatalkan semua rule yang masih pending. Verdict rule tersebut menjadi `Skipped`. |

Nilai tidak valid melempar `InvalidArgumentException`.

```php
$options = (new RuleEngineOptions(concurrency: 8, perRuleTimeout: 2.0))
    ->withFailFast();
```

Catatan fail-fast:

- Rule yang belum mulai atau sedang menunggu izin langsung dibatalkan dan
  menjadi `Skipped`.
- Rule yang sedang berjalan diinterupsi di titik suspensi berikutnya. Rule
  boleh menangkap `TaskCancelledException` dan mengembalikan verdict sendiri.
- Rule yang tidak pernah suspend tetap berjalan sampai selesai.
- Verdict yang sudah terekam tidak pernah ditimpa.

### Menjalankan engine

`AsyncRuleEngineInterface` menyediakan dua entry point dengan signature
`(iterable $rules, mixed $subject = null, ?RuleEngineOptions $options = null): RuleReport`:

- `run()` — driver blocking tingkat atas. Membungkus `FiberScheduler::run()`.
  Gunakan dari kode sinkron biasa.
- `evaluate()` — dipanggil dari dalam coroutine yang sudah berjalan di
  scheduler yang sama.

```php
use Zef\Framework\Rules\AsyncRuleEngine;
use Zef\Framework\Rules\RuleEngineOptions;
use Zef\Framework\Runtime\Async\FiberScheduler;

$engine = new AsyncRuleEngine(new FiberScheduler());

$report = $engine->run(
    [new QuotaRule(), new SignatureRule(), new RegionRule()],
    subject: $request,
    options: (new RuleEngineOptions(concurrency: 8, perRuleTimeout: 2.0))
        ->withFailFast(),
);

if (!$report->allPassed()) {
    foreach ($report->failures() as $verdict) {
        echo $verdict->ruleName(), ': ', $verdict->message(), PHP_EOL;
    }
}
```

Pemetaan hasil rule ke verdict:

| Hasil rule | Verdict |
|------------|---------|
| Rule mengembalikan `RuleVerdict` | Dipakai apa adanya. |
| Rule melempar throwable lain | `Failed` via `fromThrowable()`. |
| Deadline `perRuleTimeout` terlewati | `Failed` via `timeout()`. |
| Dibatalkan oleh fail-fast | `Skipped`. |

Engine dapat dipakai ulang untuk beberapa evaluasi.

## Lihat juga

- [`CHANGELOG-v2.26.0.md`](CHANGELOG-v2.26.0.md) — rilis async runtime.
- [`CHANGELOG-v2.27.0.md`](CHANGELOG-v2.27.0.md) — rilis async rules engine.
- [`DEPLOYMENT.md`](DEPLOYMENT.md) — pola produksi RoadRunner.
