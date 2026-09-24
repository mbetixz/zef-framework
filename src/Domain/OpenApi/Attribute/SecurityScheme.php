<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\SecuritySchemeType;

/**
 * Documented security scheme definition; repeatable on the #[OpenApi]
 * annotated class.
 *
 * #[SecurityScheme(name: 'bearer', type: SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class SecurityScheme
{
    public function __construct(
        public string $name,
        public SecuritySchemeType $type,
        public ?string $scheme = null,
        public ?string $bearerFormat = null,
        public ?ParameterLocation $in = null,
        public ?string $openIdConnectUrl = null,
        public string $description = '',
    ) {}
}
