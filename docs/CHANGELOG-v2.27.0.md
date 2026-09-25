# ZEF Framework — CHANGELOG v2.27.0

**Async Rules — evaluasi rule konkuren di atas fiber scheduler, tanpa dependensi baru**

> PHP 8.4 · zero new runtime dependencies · hexagonal: Domain ports + Application kernel
>
> Zona mutasi baru `app-rules`: **MSI 96.40% / covered 98.17%** (111 mutan, 105 terdeteksi,
> 2 escape ekuivalen terdokumentasi + 2 not-covered defensif). Coverage gate 95.67%,
> PHPStan level max + strict rules tanpa penambahan baseline, Deptrac 0 pelanggaran.

## Ringkasan

v2.27.0 menuntaskan item roadmap **"Async rules"**: sebuah rules engine yang
mengevaluasi setiap rule sebagai coroutine-nya sendiri di atas runtime asinkron
v2.26.0 (`FiberScheduler`), lengkap dengan pembatas konkurensi, deadline
kooperatif per rule, dan fail-fast — semuanya deterministik, single-threaded,
tanpa preemption, dan tanpa dependensi baru.

Desain hexagonal tetap ketat:

- **Domain** (`src/Domain/Rules/`): port + value object murni —
  `RuleInterface`, `RuleVerdict`, `RuleVerdictStatus`, `RuleReport`,
  `RuleEngineOptions`, dan port `AsyncRuleEngineInterface`.
- **Application** (`src/Application/Rules/`): kernel `AsyncRuleEngine`
  (readonly class) yang menyusun `FiberScheduler`, `Semaphore`,
  `CancellationTokenSource`, dan timer monotonik menjadi satu evaluasi total:
  satu verdict per rule, dalam urutan input, tanpa pernah melempar exception
  untuk masalah level-rule.

Deptrac: Domain → Compat saja; Application → Domain. Nol edge baru keluar lapisan.

## Domain — `Zef\Framework\Rules`

- **`RuleInterface`** — rule bernama: `name()` + `evaluate(mixed $subject,
  CancellationTokenInterface $cancellation): RuleVerdict`. Rule berjalan di
  dalam coroutine engine, jadi rule boleh suspend (`sleep`, channel, dsb.) dan
  mendapat konkurensi nyata. Kontrak cancellation: token adalah sinyal
  kooperatif (rule boleh memeriksa `isCancelled()` dan kembali dengan
  `RuleVerdict::skip()` secara sukarela), `TaskCancelledException` yang lolos
  dari rule direservasi untuk cancellation engine (fail-fast / deadline).
- **`RuleVerdict`** — VO immutable dengan tiga status terminal:
  `pass()` / `fail()` / `skip()`, ditambah `fromThrowable()` (pesan
  `"class: message"`, throwable asli tetap bisa diambil) dan `timeout()`
  (hasil deadline engine). Metadata `array<string, mixed>`, `forRule()`
  untuk anotasi nama rule oleh engine, dan predicate `isPassed()/
  isFailed()/isSkipped()`.
- **`RuleVerdictStatus`** — enum `Passed | Failed | Skipped`.
- **`RuleReport`** — agregat satu evaluasi: satu verdict per rule input,
  berurutan sesuai input; `count()`, `allPassed()` (false jika ada yang
  Failed — skipped tidak memveto, rule set kosong lolos vakum), plus
  partisi `passed()` / `failures()` / `skipped()`.
- **`RuleEngineOptions`** — VO immutable bergaya wither: `concurrency`
  (null = unlimited), `perRuleTimeout` detik (null = tanpa deadline),
  `failFast` (default off); validasi ketat (concurrency ≥ 1, timeout ≥ 0).
- **`AsyncRuleEngineInterface`** — port evaluasi: `evaluate()` (dipanggil di
  dalam coroutine) dan `run()` (driver blocking tingkat atas yang membungkus
  `FiberScheduler::run()`).

## Application — `AsyncRuleEngine`

Struktur per evaluasi (semua handle tetap internal engine):

- **Body task per rule** (`rule-<name>`): mengambil izin semaphore, spawn
  inner task yang memanggil rule, meng-await-nya, lalu mengonversi setiap
  kemungkinan hasil menjadi tepat satu verdict — body total dan tidak pernah
  gagal.
- **Inner task per rule** (`eval-<name>`): invokasi rule mentah di bawah
  token cancellation milik evaluasi.
- **Guard timer per rule ber-deadline** (`eval-<name>-deadline`): membatalkan
  inner task saat deadline menang, yang muncul di body sebagai
  `TaskCancelledException`.

Pemetaan cancellation di body (satu-satunya tempat verdict diputuskan):

- `TaskCancelledException` + evaluasi dibatalkan → **Skipped** (fail-fast
  menang race);
- `TaskCancelledException` tanpa permintaan cancellation → **Failed timeout**
  (guard adalah satu-satunya canceller lain — deadline menang; rule yang
  melempar `TaskCancelledException` untuk alasan sendiri dilaporkan sama);
- throwable lain → **Failed** membawa throwable asli;
- verdict yang dikembalikan rule → dipakai apa adanya.

Semantik penting:

- **Konkurensi**: "unlimited" direalisasikan sebagai satu izin per rule —
  pool tidak pernah starve, tidak ada coroutine yang park, sehingga kasus
  capped dan uncapped melewati satu jalur kode yang sama.
- **Fail-fast**: verdict Failed pertama membatalkan semua sibling yang masih
  pending — body yang belum mulai/park dibatalkan langsung, sedangkan rule
  in-flight diinterupsi lewat inner task-nya agar rule berkesempatan settle
  secara graceful (menangkap `TaskCancelledException` dan mengembalikan
  verdict buatannya sendiri). Rule yang tidak pernah suspend tetap berjalan
  sampai selesai (jaminan kooperatif runtime).
- **Preservasi verdict**: verdict yang sudah terekam tidak pernah ditimpa,
  bahkan ketika bookkeeping fail-fast itu sendiri meledak (callback token
  yang melempar).
- **Kebersihan timer**: guard yang tidak sempat menembak selalu di-cancel —
  pump tidak pernah idle-wait menunggu deadline rule yang sudah selesai
  (diuji lewat jam monotonik fake).

## Penggunaan

```php
$engine = new AsyncRuleEngine(new FiberScheduler());

$report = $engine->run(
    [
        new QuotaRule(),
        new SignatureRule(),
        new RegionRule(), // bisa suspend: sleep, channel, HTTP, ...
    ],
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

## Mutasi & bukti kualitas

- Zona baru **`app-rules`** (Domain + Application Rules): 111 mutan,
  **MSI 96.40% / covered 98.17%** — di atas target 95.
- 2 escape didokumentasikan sebagai ekuivalen: kedua lengan catch kembar di
  loop await identik pada semua jalur yang dapat dijangkau (verdict yang
  sudah terekam tidak pernah ditimpa — preservasi `??=`), dan
  `array_values` pasca-`ksort` hanya menjamin tipe list (preseden channel
  v2.26).
- Test: 35 test baru — deterministik penuh lewat `FakeAsyncClock` +
  `FakeAsyncSleeper`, mencakup konstruksi verdict, agregasi report, validasi
  options, urutan input + anotasi nama + pass-through subject, batas
  konkurensi (peak terukur), deadline (rule lambat gagal, rule cepat lolos,
  deadline 0 detik kooperatif), fail-fast (skip queued/park, interupsi
  in-flight, graceful verdict buatan rule, timeout memicu fail-fast), rule
  yang melempar, hostile token callback (preservasi verdict), dan reuse
  engine antar-run.
- Guard ratchet zona `app-rules` dibekukan di 96.40% pada
  `docs/mutation/{baseline,zones}.tsv` + bukti
  `docs/mutation/evidence/infection-summary-app-rules.json`.

## Kompatibilitas

- Tidak ada perubahan API publik lama; fitur murni aditif.
- `ZefVersion::VERSION` → `2.27.0`.
- PHP ≥ 8.4 (readonly class + `#[\Override]`).
