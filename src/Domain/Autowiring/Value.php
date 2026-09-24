<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring attributes).

namespace Zef\Framework\Autowiring;

/**
 * Resolves a scalar/primitive constructor parameter from engine configuration.
 *
 *     public function __construct(#[Value('db.host')] string $host) {}
 *
 * The referenced value is materialised as a synthetic singleton value service
 * (`@value:<key>`), so it appears in the dependency graph and is validated
 * like any other dependency. Parameter defaults remain the fallback when
 * this attribute is absent.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Value
{
    public function __construct(
        public string $key,
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $key) !== 1) {
            throw new \InvalidArgumentException("#[Value] key '{$key}' is invalid: expected [A-Za-z0-9._:-]{1,190}.");
        }
    }
}
