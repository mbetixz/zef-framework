<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Security scheme types defined by the OpenAPI specification.
 */
enum SecuritySchemeType: string
{
    case ApiKey = 'apiKey';
    case Http = 'http';
    case OAuth2 = 'oauth2';
    case OpenIdConnect = 'openIdConnect';
    case MutualTls = 'mutualTLS';
}
