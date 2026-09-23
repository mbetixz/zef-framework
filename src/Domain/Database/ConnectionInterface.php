<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Outbound port for SQL connections.
 *
 * The port is deliberately small: execute/fetch prepared statements built
 * by {@see QueryBuilder} (or raw DDL via {@see SqlQuery::raw()}), plus a
 * nested-transaction primitive with explicit savepoint semantics.
 *
 * Implementations must map every driver error onto the DatabaseException
 * hierarchy and never leak driver-specific exception types.
 */
interface ConnectionInterface
{
    /**
     * Run a statement, returning the number of affected rows.
     *
     * @throws QueryException when preparation or execution fails
     */
    public function execute(SqlQuery $query): int;

    /**
     * Fetch every result row as a string-keyed map.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(SqlQuery $query): array;

    /**
     * Fetch the first result row, or null when the result is empty.
     *
     * @return null|array<string, mixed>
     */
    public function fetchOne(SqlQuery $query): ?array;

    /**
     * Last auto-generated id, or null when the driver/last statement
     * produced none (e.g. PostgreSQL without a sequence name).
     */
    public function lastInsertId(): ?string;

    /**
     * Begin a transaction. Calling it while already inside one opens a
     * SAVEPOINT (nesting); $isolation may only be requested at level 0.
     */
    public function beginTransaction(?IsolationLevel $isolation = null): void;

    /**
     * Commit the current level — releases a savepoint when nested,
     * commits for real at the outermost level.
     */
    public function commit(): void;

    /**
     * Roll the current level back — restores a savepoint when nested,
     * rolls back for real at the outermost level.
     */
    public function rollBack(): void;

    /**
     * Current nesting depth: 0 = no transaction, 1 = real transaction,
     * 2+ = inside savepoints.
     */
    public function transactionLevel(): int;

    /**
     * Run $fn inside a transaction, rolling back on any throwable.
     * Calls may nest — inner calls transparently become savepoints.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T $fn
     *
     * @return T
     */
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed;
}
