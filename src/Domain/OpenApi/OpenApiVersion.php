<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)
 * Pure value objects and contracts; no I/O, no framework coupling.
 * Specification documents are assembled in the Application layer and
 * serialized by Infrastructure adapters (JSON/YAML).
 */

namespace Zef\Framework\OpenApi;

/**
 * Supported OpenAPI specification versions.
 *
 * v3.1.0 is the default target; v3.0.3 is available for tooling that has
 * not caught up with the 3.1 revision yet.
 */
enum OpenApiVersion: string
{
    case V3_0_3 = '3.0.3';
    case V3_1_0 = '3.1.0';
}
