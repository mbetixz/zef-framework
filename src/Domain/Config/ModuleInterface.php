<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

interface ModuleInterface
{
    public function getName(): string;

    public function getDefinition(): ModuleDefinition;

    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;

    public function start(ModuleContext $context): void;

    public function shutdown(ModuleContext $context): void;
}
