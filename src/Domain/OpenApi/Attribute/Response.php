<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Operation response metadata; repeatable for multiple statuses.
 *
 * #[Response(status: 200, description: 'User found', schema: UserDto::class)]
 * #[Response(status: 404, description: 'User not found')]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Response
{
    public function __construct(
        public int $status,
        public string $description = '',
        public ?string $schema = null,
        public string $mediaType = 'application/json',
    ) {}
}
