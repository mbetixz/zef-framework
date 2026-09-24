<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Raised when a specification document cannot be assembled or is
 * structurally invalid (duplicate operations, missing sections, ...).
 */
final class SpecificationException extends OpenApiException {}
