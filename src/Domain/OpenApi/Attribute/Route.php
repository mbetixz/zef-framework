<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

/**
 * Operation metadata override for the documented endpoint; repeatable.
 * Applied to the handler's handle() (or __invoke()) method and matched
 * by HTTP method (+ optionally by exact path).
 *
 * #[Route(method: 'GET', path: '/users/{id}', summary: 'Get one user')]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $method = 'GET',
        public string $path = '',
        public ?string $operationId = null,
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public bool $deprecated = false,
    ) {}
}
