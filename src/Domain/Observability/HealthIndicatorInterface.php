<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * A named, side-effect-free health probe.
 *
 * Implementations answer ONE question: is the dependency this object owns
 * usable right now? Probes run on every scrape of the aggregate health
 * endpoint, so they must be cheap (no heavy I/O, bounded timeouts) and must
 * not mutate state. Throw nothing: failures are expressed through the result.
 */
interface HealthIndicatorInterface
{
    /** Stable machine name, e.g. "cache", "queue", "database". */
    public function name(): string;

    public function check(): HealthCheckResult;
}
