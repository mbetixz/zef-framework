<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final readonly class ExplicitCorrelationContextCarrier implements CorrelationContextCarrierInterface
{
    public function __construct(private ?CorrelationContext $context) {}

    #[\Override]
    public function correlationContext(): ?CorrelationContext
    {
        return $this->context;
    }
}
