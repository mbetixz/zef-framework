<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring attributes).

namespace Zef\Framework\Autowiring;

/**
 * Binds an interface/abstract-class typed parameter to one concrete class.
 *
 *     public function __construct(
 *         #[Target(FilesystemCache::class)] CacheInterface $cache,
 *     ) {}
 *
 * The concrete class is autowired (recursively) under its own FQCN service
 * ID if it is not registered yet. Unlike a global alias, the binding is
 * local to the parameter.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Target
{
    /** @param class-string $class */
    public function __construct(
        public readonly string $class,
    ) {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            throw new \InvalidArgumentException("#[Target] references unknown class/interface/enum '{$class}'.");
        }
    }
}
