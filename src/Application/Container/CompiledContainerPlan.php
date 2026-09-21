<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Immutable, pre-resolved dependency graph used by the runtime resolver.
 *
 * @internal
 */
final readonly class CompiledContainerPlan
{
    /**
     * @param array<string,ServiceDefinition> $definitions
     * @param array<string,string> $canonicalIds service/alias id => canonical id
     * @param array<string,list<string>> $dependencies canonical id => canonical dependency ids
     */
    public function __construct(
        public array $definitions,
        public array $canonicalIds,
        public array $dependencies,
    ) {}

    public function canonical(string $id): ?string
    {
        return $this->canonicalIds[$id] ?? null;
    }

    /** @return list<string> */
    public function dependenciesOf(string $id): array
    {
        return $this->dependencies[$id] ?? [];
    }
}

/*
 * Compiles the mutable registration registry into an immutable runtime plan.
 *
 * @internal
 */
