<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.22.1 (issue #65, item 2 — UoW retry strategy for transient
 * failures).
 */

namespace Zef\Framework\Database;

/**
 * Opt-in retry policy for the {@see UnitOfWork} flush phase.
 *
 * The framework's standing contract is "the surrounding transaction
 * rollback is the failure story; a retried command re-records fresh
 * operations". This value object carves a narrow exception for
 * transient DB failures at the COMMIT/FLUSH boundary — deadlock,
 * lock-wait-timeout, serialization-failure — where retrying the same
 * writes on a fresh connection snapshot is safe and the alternative
 * is surfacing a 5xx to the caller for a problem that resolves itself
 * in milliseconds.
 *
 * Design contract (see docs/TRANSACTION-HOOKS.md §"UoW retry strategy"):
 *
 * - Only the FLUSH phase is retried — never the command handler body.
 *   Side effects produced during dispatch (event publishes, log writes)
 *   cannot be re-run safely.
 * - The UnitOfWork queue is cleared on the FIRST flush attempt (existing
 *   v2.22.0 behaviour); a retry therefore records a fresh queue snapshot
 *   per attempt. Callers that need deterministic ordering should NOT
 *   enable retry.
 * - Default is OFF: passing `null` to the {@see TransactionalCommandBus}
 *   preserves the v2.22.0 no-retry behaviour.
 *
 * @see \Zef\Framework\Job\RetryPolicy for the Job-layer sibling — this
 *      value object mirrors its validation/backoff shape but adds the
 *      transient-failure taxonomy (retryable class names + SQLSTATE
 *      codes) specific to DB writes.
 */
final readonly class UnitOfWorkRetryPolicy
{
    /**
     * Default retryable SQLSTATE codes — see
     * https://www.postgresql.org/docs/current/errcodes-appendix.html
     * and https://dev.mysql.com/doc/mysql-errors/mysql-error-rel/en/ .
     *
     * - 40001 (serialization_failure) — Postgres / SQL standard
     * - 40P01 (deadlock_detected) — Postgres
     * - 55P03 (lock_not_available / lock-wait-timeout) — Postgres
     * - 1213 (ER_LOCK_DEADLOCK) — MySQL/MariaDB
     * - 1205 (ER_LOCK_WAIT_TIMEOUT) — MySQL/MariaDB
     * - 1040 (ER_CON_COUNT_ERROR) — too many connections (transient)
     * - 2006 (CR_SERVER_GONE / CR_SERVER_LOST) — connection lost
     */
    public const array DEFAULT_RETRYABLE_SQL_STATES = [
        '40001',
        '40P01',
        '55P03',
        '1213',
        '1205',
        '1040',
        '2006',
    ];

    /**
     * @param int                  $maxAttempts           Total attempts
     *        including the first one. Must be ≥ 1.
     * @param int                  $initialDelayMs        Delay before the
     *        2nd attempt. Must be ≥ 0.
     * @param int                  $maxDelayMs            Upper bound on the
     *        computed backoff. Must be ≥ $initialDelayMs.
     * @param float                $multiplier            Backoff growth factor.
     *        Must be ≥ 1.0.
     * @param int                  $jitterMs              Randomised ± jitter
     *        added to each delay to avoid thundering-herd. Must be ≥ 0.
     * @param list<string>         $retryableClassNames   FQCNs matched with
     *        `instanceof`. A throwable is retryable if it is an instance of
     *        any listed class. Default: PDOException (parent for the
     *        framework's PDO adapter).
     * @param list<string>         $retryableSqlStates    SQLSTATE codes
     *        matched against {@see \PDOException::getCode()} and
     *        {@see \PDOException::errorInfo}[0]. Empty list = match on
     *        class names only.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelayMs = 100,
        public int $maxDelayMs = 30_000,
        public float $multiplier = 2.0,
        public int $jitterMs = 0,
        public array $retryableClassNames = ['PDOException'],
        public array $retryableSqlStates = self::DEFAULT_RETRYABLE_SQL_STATES,
    ) {
        if (
            $maxAttempts < 1
            || $initialDelayMs < 0
            || $maxDelayMs < $initialDelayMs
            || $multiplier < 1.0
            || $jitterMs < 0
        ) {
            throw new \InvalidArgumentException('Invalid UnitOfWork retry policy.');
        }
    }

    /**
     * True when an additional attempt is permitted — i.e. the policy has
     * not been exhausted yet.
     *
     * @param int $attempt 1-indexed: 1 = first attempt, 2 = first retry, …
     */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    /**
     * Milliseconds to sleep before the next attempt. Exponential backoff
     * shaped by `$multiplier`, capped by `$maxDelayMs`, with optional
     * randomised jitter.
     *
     * @param int $attempt 1-indexed: pass the attempt that just failed.
     */
    public function delayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Attempt must be positive.');
        }
        $raw = (int) round($this->initialDelayMs * ($this->multiplier ** max(0, $attempt - 1)));
        $delay = min($this->maxDelayMs, $raw);
        if ($this->jitterMs > 0) {
            $delay += random_int(0, $this->jitterMs);
        }

        return min($this->maxDelayMs, $delay);
    }

    /**
     * True when the throwable is retryable per the policy:
     *   - matches one of `$retryableClassNames` via instanceof, AND
     *   - if SQLSTATE filtering is configured, the throwable's SQLSTATE
     *     (from getCode() or PDO errorInfo) is in `$retryableSqlStates`.
     *
     * When `$retryableSqlStates` is empty, only the class-name match
     * applies.
     */
    public function isRetryable(\Throwable $e): bool
    {
        if (!$this->matchesClassName($e)) {
            return false;
        }
        if ($this->retryableSqlStates === []) {
            return true;
        }

        return $this->matchesSqlState($e);
    }

    private function matchesClassName(\Throwable $e): bool
    {
        foreach ($this->retryableClassNames as $fqcn) {
            if ($e instanceof $fqcn) {
                return true;
            }
        }

        return false;
    }

    private function matchesSqlState(\Throwable $e): bool
    {
        $candidates = [$e->getCode()];
        if ($e instanceof \PDOException && isset($e->errorInfo) && is_array($e->errorInfo)) {
            $candidates[] = $e->errorInfo[0] ?? null;
            $candidates[] = $e->errorInfo[1] ?? null;
        }
        foreach ($this->retryableSqlStates as $state) {
            if (in_array($state, $candidates, true)) {
                return true;
            }
        }

        return false;
    }
}
