<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.30.0 Ecosystem Ports: connection decorator that makes
 * DELETE claims against the job queue "lose" the race (affected rows 0),
 * to pin PdoJobQueue's raced-candidate branch without real concurrency.
 */

namespace Zef\Test\Unit;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\SqlQuery;

/**
 * Delegates everything to the inner connection; execute() returns 0 for
 * DELETE statements hitting the queue table (simulating a concurrent
 * worker that deleted the candidate first), and transaction() calls are
 * counted so tests can pin the retry economics (one scan on an empty
 * queue vs the full bounded scan under continuous races).
 *
 * @internal
 */
final class RaceLosingConnection implements ConnectionInterface
{
    public int $transactions = 0;

    public function __construct(private readonly ConnectionInterface $inner, private readonly string $queueTable) {}

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        if (str_starts_with($query->sql, 'DELETE') && str_contains($query->sql, '"' . $this->queueTable . '"')) {
            return 0;
        }

        return $this->inner->execute($query);
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return $this->inner->fetchAll($query);
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->inner->fetchOne($query);
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->inner->beginTransaction($isolation);
    }

    #[\Override]
    public function commit(): void
    {
        $this->inner->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        ++$this->transactions;

        return $this->inner->transaction($fn, $isolation);
    }
}
