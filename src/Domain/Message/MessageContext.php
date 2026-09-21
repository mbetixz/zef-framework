<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

use Zef\Framework\Validation\Identifier;

final readonly class MessageContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $attributes = [],
    ) {
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'message correlation ID');
        }
        if ($traceParent !== null) {
            Identifier::assertTraceParent($traceParent, 'W3C traceparent');
        }
        foreach ($attributes as $key => $_) {
            if ($key === '' || strlen($key) > 128) {
                throw new \InvalidArgumentException('Invalid message context attribute key.');
            }
        }
    }
}
