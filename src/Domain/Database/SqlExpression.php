<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Explicit escape hatch embedding raw SQL into a query builder column or
 * value position (functions like NOW(), expressions, aggregates).
 *
 * Nothing inside a SqlExpression is ever quoted or parameterised — this is
 * the ONLY way to smuggle raw SQL into QueryBuilder, so call sites stay
 * greppable and code review sees every raw fragment.
 */
final readonly class SqlExpression implements \Stringable
{
    public function __construct(
        public string $expression,
    ) {
        if (trim($expression) === '') {
            throw new QueryException('SqlExpression must not be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}
