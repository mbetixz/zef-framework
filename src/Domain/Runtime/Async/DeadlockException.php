<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Raised by the scheduler when no progress is possible anymore.
 *
 * A deadlock means every remaining task is suspended, no timers are pending,
 * and nothing is queued to wake them — typically awaiting a channel nobody
 * will send on or a WaitGroup nobody will finish. The message names the
 * suspended tasks so the await-chain that froze the run is immediately
 * visible in logs.
 */
final class DeadlockException extends AsyncException {}
