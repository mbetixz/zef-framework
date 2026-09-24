<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Port for DTO classes that describe their own OpenAPI schema without
 * attribute scanning. The SchemaGenerator consults this contract first,
 * then falls back to reflection over #[Schema]/#[Property] attributes.
 */
interface SchemaDefinitionInterface
{
    /** Component key under components/schemas, e.g. "User". */
    public static function openApiSchemaName(): string;

    public static function openApiSchema(): Schema;
}
