<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Class-level schema metadata for DTOs. Property-level details live on
 * #[Property]; the generator also derives constraints from native PHP
 * property types when attributes are absent.
 *
 * #[Schema(name: 'User', description: 'A user account')]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Schema
{
    public function __construct(
        public ?string $name = null,
        public string $description = '',
        public bool $deprecated = false,
    ) {}
}
