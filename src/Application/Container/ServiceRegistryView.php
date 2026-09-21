<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Read-only view of the service registry exposed to application code.
 */
final class ServiceRegistryView
{
    public function __construct(private readonly ServiceRegistry $registry) {}

    /** @return array<string,ServiceDefinition> */
    public function definitions(): array
    {
        return $this->registry->definitions();
    }

    /** @return array<string,mixed> */
    public function factories(): array
    {
        return $this->registry->factories();
    }

    /** @return array<string,string> */
    public function aliases(): array
    {
        return $this->registry->aliases();
    }

    /** @return array<string,array<int|string,mixed>> */
    public function depsOf(): array
    {
        return $this->registry->depsOf();
    }

    /** @return array<string,null|string> */
    public function moduleOf(): array
    {
        return $this->registry->moduleOf();
    }

    /** @return array<string,string> */
    public function lifetimeOf(): array
    {
        return $this->registry->lifetimeOf();
    }
}
