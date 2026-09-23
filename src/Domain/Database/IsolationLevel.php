<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Standard SQL transaction isolation levels.
 *
 * The string value is the exact SQL token used by
 * `SET TRANSACTION ISOLATION LEVEL <value>`. Adapter support varies —
 * MySQL/PostgreSQL accept every level, SQLite rejects the concept.
 */
enum IsolationLevel: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted = 'READ COMMITTED';
    case RepeatableRead = 'REPEATABLE READ';
    case Serializable = 'SERIALIZABLE';
}
