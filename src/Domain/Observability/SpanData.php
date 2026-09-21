<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Converted to final readonly class (was plain final class).
 */
final readonly class SpanData
{
    /**
     * @param array<string,mixed> $attributes
     * @param list<array{name:string,time_unix_nano:int,attributes:array<string,mixed>}> $events
     */
    public function __construct(
        public string $name,
        public SpanContext $context,
        public ?SpanContext $parent,
        public int $startNs,
        public int $endNs,
        public int $startUnixNano,
        public int $endUnixNano,
        public string $status,
        public ?string $statusDescription,
        public array $attributes,
        public array $events,
    ) {}

    public function durationSeconds(): float
    {
        return max(0, $this->endNs - $this->startNs) / 1_000_000_000;
    }
}
