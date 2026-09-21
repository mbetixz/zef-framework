<?php

declare(strict_types=1);

/*
 * ZEF Framework — Demo modules
 * Added in the v2.8.0 roadmap continuation (tagged demo health indicator).
 */

namespace Zef\Module\Health;

use Psr\Container\ContainerInterface;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\HealthIndicatorInterface;

/**
 * Demo probe: verifies the container can still resolve the `health.canary`
 * singleton. Registered under the `health.indicator` tag so the aggregate
 * endpoint picks it up through the TaggedServiceLocator.
 */
final class ContainerHealthIndicator implements HealthIndicatorInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'container';
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        try {
            $canary = $this->container->get('health.canary');

            return $canary instanceof CanaryService
                ? HealthCheckResult::up('canary resolvable')
                : HealthCheckResult::down('canary resolved to unexpected type: ' . get_debug_type($canary));
        } catch (\Throwable $e) {
            return HealthCheckResult::down('canary unresolvable: ' . $e::class);
        }
    }
}
