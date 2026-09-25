<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

abstract readonly class AbstractModule implements ModuleInterface
{
    public function __construct(private ModuleDefinition $definition) {}

    #[\Override]
    public function getName(): string
    {
        return $this->definition->name;
    }

    #[\Override]
    public function getDefinition(): ModuleDefinition
    {
        return $this->definition;
    }

    #[\Override]
    public function register(ModuleContext $context): void {}

    #[\Override]
    public function boot(ModuleContext $context): void {}

    #[\Override]
    public function start(ModuleContext $context): void {}

    #[\Override]
    public function shutdown(ModuleContext $context): void {}
}
