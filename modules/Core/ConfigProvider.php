<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo modules
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Module\Core;

use Zef\Framework\Config\ConfigProviderInterface;

final class ConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'core';
    }

    #[\Override]
    public function getConfig(): array
    {
        return [
            'services' => [
                'core.handler.home' => [
                    'factory' => static fn (): HomeHandler => new HomeHandler(),
                    'deps' => [],
                ],
                'core.handler.about' => [
                    'factory' => static fn (): AboutHandler => new AboutHandler(),
                    'deps' => [],
                ],
            ],
            'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'core.handler.home', 'priority' => 100],
                ['method' => 'GET', 'path' => '/about', 'handler' => 'core.handler.about', 'priority' => 100],
            ],
        ];
    }
}
