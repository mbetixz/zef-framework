<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Domain layer (Autowiring attributes).
 *
 * Pure declarative metadata. Attributes carry data only; all resolution
 * policy lives in the Application layer (AutowireCompilerPass).
 */

namespace Zef\Framework\Autowiring;

/**
 * Explicit service binding for a constructor parameter.
 *
 * Accepts either a service ID string or a class name — `SecretKey::class`
 * compiles to a plain string, so both spellings share one attribute.
 *
 *     public function __construct(#[Inject('db.connection')] \PDO $pdo) {}
 *     public function __construct(#[Inject(SecretKey::class)] SecretKey $key) {}
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    /** @param class-string|non-empty-string $id */
    public function __construct(
        public string $id,
    ) {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('#[Inject] requires a non-empty service ID or class name.');
        }
    }
}
