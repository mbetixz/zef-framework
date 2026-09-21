<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

use Zef\Framework\Event\EventContext;
use Zef\Framework\Validation\Identifier;

final readonly class CqrsContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $correlationId,
        public ?string $traceParent = null,
        public ?string $idempotencyKey = null,
        public array $attributes = [],
    ) {
        Identifier::assertOpaqueId($correlationId, 'CQRS correlation ID');
        if ($traceParent !== null) {
            Identifier::assertTraceParent($traceParent, 'CQRS traceparent');
        }
        if ($idempotencyKey !== null) {
            Identifier::assertOpaqueId($idempotencyKey, 'CQRS idempotency key');
        }
        foreach ($attributes as $key => $_) {
            if ($key === '' || strlen($key) > 128) {
                throw new \InvalidArgumentException('Invalid CQRS context attribute key.');
            }
        }
    }

    public function toEventContext(): EventContext
    {
        return new EventContext(
            eventId: bin2hex(random_bytes(16)),
            occurredAtUnixNano: (int) (microtime(true) * 1_000_000_000),
            correlationId: $this->correlationId,
            traceParent: $this->traceParent,
            attributes: $this->attributes,
        );
    }

    /** @param array<string,mixed> $attributes */
    public static function create(
        ?string $traceParent = null,
        ?string $idempotencyKey = null,
        array $attributes = [],
    ): self {
        return new self(bin2hex(random_bytes(16)), $traceParent, $idempotencyKey, $attributes);
    }
}
