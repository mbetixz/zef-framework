<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Immutable value object pairing a SQL statement with its ordered
 * positional parameters.
 *
 * `params` MUST be a list — named placeholders are deliberately not
 * supported so parameter order is always explicit and testable.
 * Use {@see SqlQuery::raw()} for statements that carry no parameters
 * (typically DDL issued by the migrator).
 */
final class SqlQuery
{
    /**
     * @param list<mixed> $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
    ) {
        if ($sql === '') {
            throw new QueryException('SQL statement must not be empty.');
        }
        if (!array_is_list($params)) {
            throw new QueryException('SqlQuery params must be a list of positional values.');
        }
    }

    /**
     * Raw statement without parameters (DDL, admin statements).
     */
    public static function raw(string $sql): self
    {
        return new self($sql, []);
    }
}
