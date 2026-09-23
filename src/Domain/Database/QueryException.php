<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Raised when statement preparation or execution fails, or when query
 * construction violates the builder grammar (invalid identifiers, empty
 * IN() lists, missing table/data, unbounded UPDATE/DELETE without the
 * explicit escape hatch).
 */
final class QueryException extends DatabaseException {}
