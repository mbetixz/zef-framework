<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Raised when a PHP type/class cannot be mapped to a valid Schema Object
 * (invalid constraints, unmappable types, broken attribute usage).
 */
final class SchemaDefinitionException extends OpenApiException {}
