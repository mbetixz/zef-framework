<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Raised on transaction misuse: committing or rolling back outside a
 * transaction, an active migration lock, or a nested transaction that
 * cannot be reconciled with its savepoint stack.
 */
final class TransactionException extends DatabaseException {}
