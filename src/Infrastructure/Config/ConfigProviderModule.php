<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

final readonly class ConfigProviderModule extends AbstractModule
{
    public function __construct(private ConfigProviderInterface $provider)
    {
        parent::__construct(ModuleDefinition::fromArray($provider->getModuleName(), $provider->getConfig()));
    }

    public function provider(): ConfigProviderInterface
    {
        return $this->provider;
    }
}
