<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Raised by the scheduler when a deadline elapses before the guarded
 * operation completes (see FiberScheduler::timeout()).
 *
 * The underlying task is cancelled cooperatively: the deadline cancels the
 * task at its next suspension point, and the observed cancellation is
 * translated into this type at the timeout() call site.
 */
final class AsyncTimeoutException extends AsyncException {}
