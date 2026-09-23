<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: services over the port)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Contract for a single, versioned schema migration.
 *
 * Versions are 14-digit timestamps (YYYYmmddHHMMSS) applied in ascending
 * order; each migration runs inside its own transaction so a failure
 * leaves the schema untouched (DDL-in-transaction caveat applies on
 * MySQL — see the v2.18.0 changelog).
 */
interface MigrationInterface
{
    /** 14-digit version stamp, e.g. 20260923000000. */
    public function version(): string;

    /** Human-readable name, 1..128 characters. */
    public function name(): string;

    /** Apply the migration. */
    public function up(ConnectionInterface $connection): void;

    /** Revert the migration (must undo exactly what up() did). */
    public function down(ConnectionInterface $connection): void;
}
