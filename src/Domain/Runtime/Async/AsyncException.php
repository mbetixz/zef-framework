<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Base class for every async-runtime failure the framework raises.
 *
 * Catching this single type covers task cancellation, timeouts, closed
 * channels, deadlock detection and unobserved task failures, while each
 * concrete subclass keeps a specific meaning for callers that need to
 * distinguish them. Extends \RuntimeException so the exceptions remain
 * runtime failures rather than logic errors by default.
 */
class AsyncException extends \RuntimeException {}
