<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Event;

use Zef\Framework\Validation\Identifier;

final readonly class EventContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $eventId,
        public int $occurredAtUnixNano,
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $attributes = [],
    ) {
        Identifier::assertOpaqueId($eventId, 'event ID');
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'correlation ID');
        }
        if ($traceParent !== null) {
            Identifier::assertTraceParent($traceParent);
        }
    }
}
