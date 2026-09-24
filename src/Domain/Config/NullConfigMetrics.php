<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.23.0 (issue #60 P1: null-object default for
 * the config observability port — zero-cost when no telemetry is bound).
 */

namespace Zef\Framework\Config;

/**
 * Null object for {@see ConfigMetricsInterface}: absorbs every event and
 * does nothing. It is the implicit default (a null metrics instance is
 * treated identically) and the explicit "opt out" binding for deployments
 * that deliberately run config boot without telemetry.
 */
final readonly class NullConfigMetrics implements ConfigMetricsInterface
{
    #[\Override]
    public function secretRetry(string $provider, string $key): void
    {
        // Intentionally empty: no telemetry bound.
    }

    #[\Override]
    public function secretResolved(string $provider): void
    {
        // Intentionally empty: no telemetry bound.
    }

    #[\Override]
    public function secretStaleFallback(string $provider, string $key): void
    {
        // Intentionally empty: no telemetry bound.
    }
}
