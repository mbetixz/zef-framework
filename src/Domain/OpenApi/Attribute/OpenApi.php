<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)
 * Declarative metadata attributes for spec generation.
 */

namespace Zef\Framework\OpenApi\Attribute;

use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\OpenApiVersion;
use Zef\Framework\OpenApi\Server;

/**
 * Root document metadata. Applied to a class acting as the API entry
 * point (typically a bootstrap/controller class); the extractor scans
 * the configured handler classes for the first #[OpenApi] attribute.
 *
 * #[OpenApi(title: 'Zef API', version: '2.20.0')]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class OpenApi
{
    /**
     * @param list<Server> $servers
     */
    public function __construct(
        public string $title,
        public string $version,
        public string $description = '',
        public OpenApiVersion $openApiVersion = OpenApiVersion::V3_1_0,
        public array $servers = [],
        public ?string $termsOfService = null,
        public ?Contact $contact = null,
        public ?License $license = null,
    ) {}
}
