<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Raised inside a coroutine when its task has been cancelled.
 *
 * The exception surfaces exactly at the current suspension point (await,
 * channel receive, semaphore acquire, timer sleep), so `finally` blocks in
 * the coroutine body still run before the fiber terminates. It also leaks
 * out of {@see TaskInterface::result()} when a caller awaits a task that was
 * cancelled before it could produce a result.
 */
final class TaskCancelledException extends AsyncException {}
