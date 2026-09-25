# ZEF Framework — CHANGELOG v2.22.1

**v2.22.1 hardening pass** — rilis minor yang mengimplementasikan tiga
item Low-priority dari tinjauan v2.22.0 (issue #65): dokumentasi semantik
error hook, opt-in retry transient-failure UoW, dan guard slow-hook
kooperatif. Tidak ada perubahan breaking; semua fitur opt-in dengan
default yang melestarikan perilaku v2.22.0.

Closes #65.

## Ringkasan

- **Item 1 — Hook error semantics documentation (`docs/TRANSACTION-HOOKS.md`)** —
  dokumen referensi lengkap untuk siklus hidup hook
  `TransactionManagerInterface::afterCommit()`:
  - Diagram 4 fase (handler → flush → commit → drainHooks) dengan
    semantik kegagalan tiap fase;
  - Aturan empat kontrak hook: idempotency, no-DB-writes-on-same-connection
    selama commit phase, fast, capture-state-not-reference-mutation;
  - Kontrak `@throws` eksplisit di `TransactionManagerInterface` dan
    `TransactionManager` (memperjelas kegagalan hook propagasi SETELAH
    commit sukses, bukan transactional rollback).

- **Item 2 — UoW retry strategy (opt-in, `UnitOfWorkRetryPolicy`)** —
  value object kebijakan retry untuk fase flush, dengan taksonomi
  transient-failure (class names + SQLSTATE codes):
  - `UnitOfWorkRetryPolicy` (`src/Domain/Database/`) — `maxAttempts`,
    `initialDelayMs`, `maxDelayMs`, `multiplier`, `jitterMs`,
    `retryableClassNames` (default `PDOException`),
    `retryableSqlStates` (default serialization/deadlock/lock-timeout
    untuk Postgres + MySQL/MariaDB);
  - `UnitOfWork::flushRetrying($conn, $policy)` — method baru yang
    mempertahankan snapshot antrean di retry (berbeda dari
    `flush()` yang clear-before-execute per kontrak v2.22.0);
  - `TransactionalCommandBus` menerima `?UnitOfWorkRetryPolicy`
    opsional; default `null` = perilaku v2.22.0 tanpa retry.

- **Item 3 — Hook timeout guard (cooperative, `TransactionManager`)** —
  soft threshold `?int $hookDurationThresholdMs` + `?LoggerInterface`
  opsional di constructor `TransactionManager`:
  - Saat threshold di-set, setiap hook diukur via `hrtime(true)`;
    melebihi threshold memancarkan debug-log via PSR-3 logger
    (default `NullLogger` = silent);
  - Kooperatif (bukan hard timeout) — PHP tidak dapat menginterupsi
    closure berjalan secara aman; guard ini observability-only;
  - Recommended threshold: 50 ms request-scope, 500 ms job/worker-scope.

## Files

### Baru
- `docs/TRANSACTION-HOOKS.md` — dokumentasi hook error semantics lengkap.
- `src/Domain/Database/UnitOfWorkRetryPolicy.php` — value object
  kebijakan retry + taksonomi transient-failure.
- `tests/Unit/DatabaseUnitOfWorkRetryPolicyTest.php` — 13 test kasus
  validasi + backoff + retryability.
- `tests/Unit/DatabaseUnitOfWorkFlushRetryingTest.php` — 7 test kasus
  `flushRetrying()` happy path + replay + propagation + exhaustion.
- `tests/Unit/DatabaseTransactionManagerHookTimeoutTest.php` — 4 test
  kasus slow-hook guard (no-threshold silent, slow log, fast silent,
  null-logger default).

### Modifikasi
- `src/Application/Database/TransactionManager.php` — constructor baru
  opsional `?int $hookDurationThresholdMs`, `?LoggerInterface`; timing
  di `drainHooks()` via `hrtime()`; docblock `@throws` eksplisit.
- `src/Application/Database/UnitOfWork.php` — method baru
  `flushRetrying(ConnectionInterface, UnitOfWorkRetryPolicy)` yang
  preserve queue across retry; `flush()` existing UNCHANGED (kontrak
  clear-before-execute dipertahankan).
- `src/Application/CQRS/TransactionalCommandBus.php` — constructor baru
  opsional `?UnitOfWorkRetryPolicy`; delegasi ke `flushRetrying()`
  saat policy di-set, fallback ke `flush()` default.
- `src/Domain/Database/TransactionManagerInterface.php` — docblock
  `@throws` eksplisit di `withTransaction()` + `afterCommit()`.

## Kontrak yang Dipertahankan

- **`UnitOfWork::flush()`** — kontrak v2.22.0 "queue cleared BEFORE
  execution" tidak diubah. `flush()` single-shot, no retry.
- **`TransactionalCommandBus` default** — `retryPolicy = null`
  melestarikan perilaku no-retry v2.22.0.
- **`TransactionManager` default** — `hookDurationThresholdMs = null`
  melestarikan perilaku no-logging v2.22.0.
- Semua penambahan adalah parameter opsional; tidak ada signature break.

## Motivasi

Tinjauan v2.22.0 (PR #63) mengidentifikasi tiga area kerentanan minor
yang tidak blok release v2.22.0 tapi pantas di-hardening:

1. **Hook exception handling** — implementasi v2.22.0 sudah benar
   (hook failure propagates after commit), tapi kontraknya tidak
   terdokumentasi eksplisit. Caller baru bisa salah mengira hook
   gagal = rollback, padahal data sudah committed.
2. **UoW retry** — failure story v2.22.0 ("rollback is the failure
   story") cukup untuk command-level retry, tapi transient DB failures
   (deadlock, serialization-failure) adalah noise yang bisa di-retry
   aman di flush boundary tanpa re-run handler.
3. **Hook timeout** — hook yang lambat memegang resource (DB
   connection, locks) tanpa observability. Guard kooperatif
   memberikan visibilitas tanpa memperkenalkan race condition dari
   hard-timeout.

## Non-tujuan

- **Hard timeout** untuk hook — PHP tidak dapat menginterupsi closure
  berjalan secara aman. Guard kooperatif (item 3) adalah minimum viable.
- **Retry di command handler level** — di luar cakupan item 2; handler
  yang butuh retry harus re-record fresh operations, bukan replay
  queue lama.
- **Backward-compatible retry di `flush()` existing** — kontrak v2.22.0
  "queue cleared before execution" dipertahankan. Method baru
  `flushRetrying()` yang preserve queue.
- **Prisma persistence untuk history cron round** — di luar cakupan
  issue #65.

## Tes

- PHPUnit: 22 test baru (13 policy + 7 flushRetrying + 4 hook timeout).
- PHPStan: 0 error baru (semua path dianotasi).
- php-cs-fixer: clean (ikuti style guide repo).

## Lihat juga

- `docs/CHANGELOG-v2.22.0.md` — rilis asli transaction orchestration.
- `docs/CHANGELOG-v2.23.0.md` — rilis v2.23.0 (Event Sourcing Hardening).
- Issue #65 — proposal asli.
- PR #63 — sumber tinjauan (v2.22.0 Transaction orchestration & UoW-lite).
