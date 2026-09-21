<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

use Psr\Container\ContainerInterface;

final class ModuleContext
{
    public function __construct(
        private readonly ModuleDefinition $definition,
        private readonly ContainerInterface $container,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function definition(): ModuleDefinition
    {
        return $this->definition;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }
}
