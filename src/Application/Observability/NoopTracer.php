<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class NoopTracer implements TracerInterface
{
    #[\Override]
    public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface
    {
        return NoopSpan::instance();
    }
}
