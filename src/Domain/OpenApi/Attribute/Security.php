<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Security requirement reference for an operation or the whole API class.
 *
 * #[Security(scheme: 'bearer')]
 * #[Security(scheme: 'oauth2', scopes: ['read:users'])]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Security
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $scheme,
        public array $scopes = [],
    ) {
        if (trim($scheme) === '') {
            throw new \InvalidArgumentException('Security scheme name must not be empty.');
        }
    }
}
