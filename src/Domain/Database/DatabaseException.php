<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Base exception for every database-related failure.
 *
 * The database package never leaks raw PDO exceptions to the caller:
 * drivers are mapped onto ConnectionException (connect/config phase),
 * QueryException (prepare/execute phase) and TransactionException
 * (transaction misuse) so applications can catch one stable type.
 */
abstract class DatabaseException extends \RuntimeException {}
