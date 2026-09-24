<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Attribute;

use Zef\Framework\OpenApi\SchemaType;

/**
 * Property-level schema metadata. Constraints declared here refine (or
 * replace) the constraints inferred from the native PHP type.
 *
 * #[Property(type: SchemaType::String, format: 'email', maxLength: 254)]
 *
 * `ref` accepts either a component schema name ("User") or a class-string
 * of a DTO resolvable by the SchemaGenerator.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class Property
{
    /**
     * @param null|list<bool|float|int|string> $enum
     */
    public function __construct(
        public SchemaType $type = SchemaType::String,
        public ?string $format = null,
        public string $description = '',
        public ?bool $required = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public ?bool $deprecated = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?SchemaType $itemsType = null,
        public ?string $itemsRef = null,
        public ?string $ref = null,
        public mixed $default = null,
        public mixed $example = null,
        public ?array $enum = null,
        public bool $nullable = false,
    ) {}
}
