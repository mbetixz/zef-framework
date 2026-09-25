<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Raised on channel operations that violate the channel lifecycle.
 *
 * Two distinct moments raise it: send() on an already-closed channel (or a
 * sender still suspended when close() lands), and receive() on a closed
 * channel whose buffer has been fully drained. Buffered values surviving a
 * close() remain receivable — the exception only fires once the channel is
 * provably empty.
 */
final class ChannelClosedException extends AsyncException {}
