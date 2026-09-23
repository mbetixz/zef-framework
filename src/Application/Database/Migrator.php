<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: services over the port)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Versioned migration runner.
 *
 * - tracks applied versions in the `zef_migrations` table;
 * - applies pending migrations in ascending version order, each inside
 *   its own transaction (up() + bookkeeping commit or roll back together);
 * - supports rollback(N) in descending order;
 * - guards concurrent runners with a single-row lock table
 *   (`zef_migrations_lock`); a lock whose age exceeds the TTL recorded
 *   ON THE ROW is considered stale and stolen;
 * - renews (heartbeats) the lock with the effective TTL right before
 *   each migration step, so a legitimately slow step (huge ALTER TABLE,
 *   index rebuild) is never stolen mid-flight — a migration may raise
 *   its own headroom via MigrationInterface::getLockTtl().
 *
 * The clock is injectable ($now returning unix seconds) so lock expiry
 * and heartbeat renewal are deterministically testable.
 */
final class Migrator
{
    public const string MIGRATIONS_TABLE = 'zef_migrations';
    public const string LOCK_TABLE = 'zef_migrations_lock';
    private const string VERSION_RE = '/^\d{14}$/';
    private const int MAX_NAME_BYTES = 128;

    private int $lockDepth = 0;

    /** @var array<string, MigrationInterface> */
    private array $registered = [];

    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(): int $now wall-clock unix seconds provider
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        ?callable $now = null,
        private readonly float $lockTtlSeconds = 300.0,
    ) {
        if ($this->lockTtlSeconds <= 0.0) {
            throw new \InvalidArgumentException('Migration lock TTL must be greater than zero.');
        }
        $this->now = $now ?? time(...);
    }

    public function register(MigrationInterface $migration): void
    {
        $version = $migration->version();
        if (preg_match(self::VERSION_RE, $version) !== 1) {
            throw new \InvalidArgumentException(
                "Migration version '{$version}' must be a 14-digit timestamp (YYYYmmddHHMMSS).",
            );
        }
        $name = $migration->name();
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) {
            throw new \InvalidArgumentException(
                'Migration name must be a string of 1..' . self::MAX_NAME_BYTES . ' bytes.',
            );
        }
        if (isset($this->registered[$version])) {
            throw new \InvalidArgumentException("Migration version '{$version}' is already registered.");
        }
        $this->registered[$version] = $migration;
    }

    /**
     * @return list<string> versions recorded as applied, ascending
     */
    public function applied(): array
    {
        $this->ensureSchema();
        $rows = $this->connection->fetchAll(
            QueryBuilder::table(self::MIGRATIONS_TABLE)->select('version')->orderBy('version')->build(),
        );
        $versions = [];
        foreach ($rows as $row) {
            $version = $row['version'] ?? null;
            if (!is_string($version)) {
                throw new QueryException('Migration bookkeeping row has a non-string version.');
            }
            $versions[] = $version;
        }

        return $versions;
    }

    /**
     * @return list<MigrationInterface> registered-but-not-applied, ascending
     */
    public function pending(): array
    {
        $done = array_fill_keys($this->applied(), true);
        $versions = array_keys($this->registered);
        sort($versions);
        $pending = [];
        foreach ($versions as $version) {
            if (!isset($done[$version])) {
                $pending[] = $this->registered[$version];
            }
        }

        return $pending;
    }

    /**
     * @return list<string> versions that migrate() would apply right now
     */
    public function plan(): array
    {
        return array_map(
            static fn (MigrationInterface $m): string => $m->version(),
            $this->pending(),
        );
    }

    /**
     * Apply every pending migration. Returns the versions applied now.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->acquireLock();

        try {
            $appliedNow = [];
            foreach ($this->pending() as $migration) {
                $version = $migration->version();
                $this->renewLock($this->effectiveTtl($migration));
                $this->connection->transaction(function (ConnectionInterface $c) use ($migration): void {
                    $migration->up($c);
                    $c->execute(
                        QueryBuilder::table(self::MIGRATIONS_TABLE)
                            ->insert(['version' => $migration->version(), 'name' => $migration->name(), 'applied_at' => ($this->now)()])
                            ->build(),
                    );
                });
                $appliedNow[] = $version;
            }

            return $appliedNow;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Roll back the $steps most recent applied migrations (descending).
     * Returns the versions rolled back.
     *
     * @return list<string>
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('Rollback steps must be >= 1.');
        }
        $this->acquireLock();

        try {
            $applied = array_reverse($this->applied());
            $targets = array_slice($applied, 0, $steps);
            if (count($targets) < $steps) {
                throw new \InvalidArgumentException(
                    "Requested {$steps} rollback step(s) but only " . count($targets) . ' applied migration(s) exist.',
                );
            }
            $rolled = [];
            foreach ($targets as $version) {
                $migration = $this->registered[$version]
                    ?? throw new \RuntimeException(
                        "Applied migration '{$version}' is not registered; cannot roll back.",
                    );
                $this->renewLock($this->effectiveTtl($migration));
                $this->connection->transaction(function (ConnectionInterface $c) use ($migration, $version): void {
                    $migration->down($c);
                    $c->execute(
                        QueryBuilder::table(self::MIGRATIONS_TABLE)->where('version', '=', $version)->delete()->build(),
                    );
                });
                $rolled[] = $version;
            }

            return $rolled;
        } finally {
            $this->releaseLock();
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function ensureSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . self::MIGRATIONS_TABLE . '" ('
            . '"version" VARCHAR(14) NOT NULL PRIMARY KEY, '
            . '"name" VARCHAR(128) NOT NULL, '
            . '"applied_at" INTEGER NOT NULL)',
        ));
    }

    private function acquireLock(): void
    {
        if ($this->lockDepth > 0) {
            throw new TransactionException('Migration lock is already held by this Migrator instance.');
        }
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . self::LOCK_TABLE . '" ('
            . '"id" INTEGER NOT NULL PRIMARY KEY, '
            . '"locked_at" INTEGER NOT NULL, '
            . '"ttl" REAL NOT NULL)',
        ));
        $now = ($this->now)();

        try {
            $this->connection->execute(
                QueryBuilder::table(self::LOCK_TABLE)
                    ->insert(['id' => 1, 'locked_at' => $now, 'ttl' => $this->lockTtlSeconds])->build(),
            );
        } catch (QueryException) {
            $rows = $this->connection->fetchAll(
                QueryBuilder::table(self::LOCK_TABLE)
                    ->select('locked_at', 'ttl')->where('id', '=', 1)->build(),
            );
            $lockedRaw = $rows[0]['locked_at'] ?? null;
            $ttlRaw = $rows[0]['ttl'] ?? null;
            if ((!is_int($lockedRaw) && !is_float($lockedRaw) && !is_string($lockedRaw))
                || (!is_int($ttlRaw) && !is_float($ttlRaw) && !is_string($ttlRaw))) {
                throw new QueryException('Migration lock row is malformed.');
            }
            $lockedAt = (int) $lockedRaw;
            $rowTtl = (float) $ttlRaw;
            $age = $now - $lockedAt;
            if ((float) $age < $rowTtl) {
                throw new TransactionException(
                    'Migration lock is already held (age ' . $age . 's, ttl ' . $rowTtl . 's).',
                );
            }
            $this->connection->execute(
                QueryBuilder::table(self::LOCK_TABLE)
                    ->update(['locked_at' => $now, 'ttl' => $this->lockTtlSeconds])->where('id', '=', 1)->build(),
            );
        }
        $this->lockDepth = 1;
    }

    /**
     * Heartbeat: refresh the lock row with the effective TTL so other
     * runners never see this holder as stale while work progresses.
     */
    private function renewLock(float $ttl): void
    {
        if ($this->lockDepth === 0) {
            return;
        }
        $this->connection->execute(
            QueryBuilder::table(self::LOCK_TABLE)
                ->update(['locked_at' => ($this->now)(), 'ttl' => $ttl])->where('id', '=', 1)->build(),
        );
    }

    /**
     * Effective TTL for a step: the migration's explicit override when
     * provided (validated positive), the Migrator default otherwise.
     */
    private function effectiveTtl(MigrationInterface $migration): float
    {
        $override = $migration->getLockTtl();
        if ($override === null) {
            return $this->lockTtlSeconds;
        }
        if ($override <= 0.0) {
            throw new \InvalidArgumentException(
                "Migration '{$migration->version()}' lock TTL override must be greater than zero (got {$override}).",
            );
        }

        return $override;
    }

    private function releaseLock(): void
    {
        if ($this->lockDepth === 0) {
            return;
        }
        $this->lockDepth = 0;
        $this->connection->execute(
            QueryBuilder::table(self::LOCK_TABLE)->where('id', '=', 1)->delete()->build(),
        );
    }
}
