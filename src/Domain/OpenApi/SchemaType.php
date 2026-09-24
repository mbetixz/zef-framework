<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Primitive JSON Schema types allowed for an OpenAPI Schema Object.
 */
enum SchemaType: string
{
    case String = 'string';
    case Number = 'number';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
    case Null = 'null';
}
