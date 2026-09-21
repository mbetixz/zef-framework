<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

interface SpanInterface
{
    public function getContext(): SpanContext;

    public function setAttribute(string $key, mixed $value): self;

    /** @param array<string,mixed> $attributes */
    public function setAttributes(array $attributes): self;

    /** @param array<string,mixed> $attributes */
    public function addEvent(string $name, array $attributes = []): self;

    public function setStatus(string $status, ?string $description = null): self;

    public function end(?int $endNs = null): void;

    public function isEnded(): bool;
}
