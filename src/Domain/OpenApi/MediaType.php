<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Frequently used content media types for request bodies and responses.
 */
enum MediaType: string
{
    case Json = 'application/json';
    case ProblemJson = 'application/problem+json';
    case FormUrlEncoded = 'application/x-www-form-urlencoded';
    case MultipartForm = 'multipart/form-data';
    case PlainText = 'text/plain';
}
