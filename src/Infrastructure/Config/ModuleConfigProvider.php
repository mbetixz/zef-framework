<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

final readonly class ModuleConfigProvider implements ConfigProviderInterface
{
    public function __construct(private ModuleInterface $module) {}

    #[\Override]
    public function getModuleName(): string
    {
        return $this->module->getName();
    }

    /** @return array<string,mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return $this->module->getDefinition()->toArray();
    }
}
