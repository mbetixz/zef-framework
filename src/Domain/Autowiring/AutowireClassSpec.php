<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring value objects).

namespace Zef\Framework\Autowiring;

/**
 * Reflection-extracted plan for one class constructor.
 */
final readonly class AutowireClassSpec
{
    /**
     * @param bool                         $hasConstructor  false when the class has no constructor at all
     * @param list<AutowireParameterSpec>  $parameters
     */
    public function __construct(
        public string $className,
        public bool $hasConstructor,
        public array $parameters,
    ) {}
}
