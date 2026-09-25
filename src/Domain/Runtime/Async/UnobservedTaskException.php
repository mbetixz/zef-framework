<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Raised when spawned tasks failed but nobody ever observed their outcome.
 *
 * Failures are never silently swallowed: after a run settles, the scheduler
 * collects the throwables of every failed task that was neither awaited nor
 * included in awaitAll() (the main task is always observed) and raises them
 * through this aggregate. The full list stays retrievable via throwables().
 */
final class UnobservedTaskException extends AsyncException
{
    /**
     * @param list<\Throwable> $throwables the failures of unobserved tasks, in task-spawn order
     */
    public function __construct(private readonly array $throwables)
    {
        $first = $throwables[0] ?? null;

        parent::__construct(sprintf(
            '%d unobserved async task failure(s); first: %s',
            count($throwables),
            $first instanceof \Throwable ? $first->getMessage() : 'unknown',
        ));
    }

    /**
     * @return list<\Throwable>
     */
    public function throwables(): array
    {
        return $this->throwables;
    }
}
