<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

use Zef\Framework\OpenApi\MediaType;

/**
 * Operation request body metadata. `schema` accepts a class-string of a
 * DTO resolvable by the SchemaGenerator.
 *
 * #[RequestBody(schema: CreateUserRequest::class, required: true)]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class RequestBody
{
    public function __construct(
        public ?string $schema = null,
        public MediaType $mediaType = MediaType::Json,
        public string $description = '',
        public bool $required = false,
    ) {}
}
