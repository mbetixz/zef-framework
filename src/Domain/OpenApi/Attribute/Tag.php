<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Documentation tag; repeatable. Class-level tags are inherited by every
 * documented operation of that class.
 *
 * #[Tag(name: 'users', description: 'User management')]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Tag
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Tag name must not be empty.');
        }
    }
}
