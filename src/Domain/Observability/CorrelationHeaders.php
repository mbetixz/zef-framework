<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final readonly class CorrelationHeaders
{
    public function __construct(
        public string $traceParent,
        public ?string $traceState,
    ) {
        if (strlen($traceParent) !== CorrelationContext::MAX_TRACEPARENT_BYTES) {
            throw new \InvalidArgumentException('Correlation traceparent must be 55 bytes.');
        }
        if ($traceState !== null && strlen($traceState) > CorrelationContext::MAX_TRACESTATE_BYTES) {
            throw new \InvalidArgumentException('Correlation tracestate exceeds the hard limit.');
        }
    }

    public function encodedBytes(): int
    {
        return strlen($this->traceParent) + ($this->traceState === null ? 0 : strlen($this->traceState));
    }
}
