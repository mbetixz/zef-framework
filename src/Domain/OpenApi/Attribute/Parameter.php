<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\SchemaType;

/**
 * Operation parameter metadata (query/header/cookie; path parameters are
 * derived from the route pattern automatically).
 *
 * #[Parameter(name: 'page', in: ParameterLocation::Query, type: SchemaType::Integer)]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Parameter
{
    public function __construct(
        public string $name,
        public ParameterLocation $in = ParameterLocation::Query,
        public SchemaType $type = SchemaType::String,
        public ?string $format = null,
        public string $description = '',
        public ?bool $required = null,
        public bool $deprecated = false,
        public mixed $example = null,
    ) {}
}
