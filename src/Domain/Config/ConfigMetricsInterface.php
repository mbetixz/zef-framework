<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.23.0 (issue #60 P1: observability port for the
 * resilient secrets pipeline — retries, resolved calls and stale-value
 * fallbacks become countable without coupling the provider to any telemetry
 * backend).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Observability\MeterInterface;

/**
 * Observability port for the configuration/secrets pipeline.
 *
 * Implementations MUST treat every argument as public operational metadata:
 * the KEY NAME of a secret (its dotted config path), never the resolved
 * secret value, is the only identifier that may reach a label. The port is
 * deliberately narrow and semantic (three counting events) so backends can
 * map it to whatever metric vocabulary they use without the provider knowing
 * about metric names, label sets or cardinality budgets.
 *
 * Events and their exact emission points inside
 * {@see ResilientSecretsProvider::get()}:
 * - {@see secretRetry()} — a call failed and WILL be retried (emitted once
 *   per failed attempt that has budget left, before the backoff sleep);
 * - {@see secretResolved()} — the inner provider answered without throwing,
 *   including the null of an unknown key: the provider call itself
 *   succeeded, which is the denominator the retry/failure ratios need;
 * - {@see secretStaleFallback()} — the retry budget was exhausted and the
 *   last known-good value was served instead.
 *
 * Null-object default ({@see NullConfigMetrics}) keeps the provider
 * dependency-free; production wiring binds {@see MeterConfigMetrics} over
 * the existing Observability {@see MeterInterface}
 * port.
 */
interface ConfigMetricsInterface
{
    /**
     * A resolution attempt against the provider named `$provider` failed and
     * a retry for the secret named `$key` will follow.
     */
    public function secretRetry(string $provider, string $key): void;

    /**
     * The provider named `$provider` answered a call without throwing
     * (the answer itself may be a null unknown-key response).
     */
    public function secretResolved(string $provider): void;

    /**
     * The provider named `$provider` failed terminally and the stale
     * known-good value for `$key` was served instead.
     */
    public function secretStaleFallback(string $provider, string $key): void;
}
