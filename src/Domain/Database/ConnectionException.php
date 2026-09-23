<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Raised when establishing or configuring a connection fails, or when a
 * driver is asked for a capability it does not support (e.g. isolation
 * levels on SQLite).
 */
final class ConnectionException extends DatabaseException {}
