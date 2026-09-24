<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Marker for deprecated handlers/operations (or whole API classes).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Deprecated
{
    public function __construct(
        public string $description = '',
    ) {}
}
