<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Event;

final class EventDispatchException extends \RuntimeException
{
    /** @param list<\Throwable> $errors */
    public function __construct(
        string $message,
        public readonly array $errors,
        public readonly object $event,
    ) {
        parent::__construct($message, 0, $errors[0] ?? null);
    }
}
