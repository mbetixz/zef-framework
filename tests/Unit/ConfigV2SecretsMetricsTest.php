<?php

declare(strict_types=1);

// ZEF Framework v2.23.0 — Configuration System v2 (issue #60 P1): the
// config observability port — semantic events from the resilient secrets
// decorator, the null-object default, and the MeterInterface adapter with
// its label bounding. Secret VALUES must never surface as label values.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigMetricsInterface;
use Zef\Framework\Config\MeterConfigMetrics;
use Zef\Framework\Config\NullConfigMetrics;
use Zef\Framework\Config\ResilientSecretsProvider;
use Zef\Framework\Config\SecretsProviderInterface;
use Zef\Framework\Observability\CounterMeter;

/**
 * @internal
 */
final class ConfigV2SecretsMetricsTest extends TestCase
{
    // ---- MeterConfigMetrics vocabulary ----------------------------------------

    public function testAdapterMapsEventsToFrameworkMetricNames(): void
    {
        $meter = new CounterMeter();
        $metrics = new MeterConfigMetrics($meter);

        $metrics->secretRetry('EnvironmentSecretProvider', 'database.password');
        $metrics->secretRetry('EnvironmentSecretProvider', 'database.password');
        $metrics->secretRetry('EnvironmentSecretProvider', 'database.password');
        $metrics->secretResolved('EnvironmentSecretProvider');
        $metrics->secretStaleFallback('EnvironmentSecretProvider', 'database.password');

        $snap = $meter->snapshot();
        self::assertSame(3, $snap['zef.config.secrets.retries.total|{"key":"database.password","provider":"EnvironmentSecretProvider"}']['count']);
        self::assertSame(1, $snap['zef.config.secrets.success.total|{"provider":"EnvironmentSecretProvider"}']['count']);
        self::assertSame(1, $snap['zef.config.secrets.fallback.total|{"provider":"EnvironmentSecretProvider"}']['count']);
    }

    public function testAdapterBoundsHostileLabelDimensions(): void
    {
        $meter = new CounterMeter();
        $metrics = new MeterConfigMetrics($meter);

        $tooLong = str_repeat('a', 97);
        $weird = "line\nbreak\x00null";
        $metrics->secretRetry($tooLong, $weird);

        $snap = $meter->snapshot();
        $series = array_values(array_filter(
            $snap,
            static fn (string $k): bool => str_starts_with($k, 'zef.config.secrets.retries.total|'),
            ARRAY_FILTER_USE_KEY,
        ));
        self::assertCount(1, $series);
        self::assertSame('[other]', $series[0]['attributes']['provider']);
        self::assertSame('[other]', $series[0]['attributes']['key']);
    }

    public function testWellFormedMaxSizeLabelsPassThrough(): void
    {
        $meter = new CounterMeter();
        $metrics = new MeterConfigMetrics($meter);

        $ninetySix = str_repeat('a', 96);
        $metrics->secretRetry('prov-1', $ninetySix);

        $snap = $meter->snapshot();
        self::assertSame($ninetySix, $snap['zef.config.secrets.retries.total|{"key":"' . $ninetySix . '","provider":"prov-1"}']['attributes']['key']);
    }

    public function testNullObjectAbsorbsEveryEvent(): void
    {
        $metrics = new NullConfigMetrics();
        $metrics->secretRetry('p', 'k');
        $metrics->secretResolved('p');
        $metrics->secretStaleFallback('p', 'k');
        self::assertInstanceOf(ConfigMetricsInterface::class, $metrics);
    }

    // ---- ResilientSecretsProvider emission ------------------------------------

    public function testProviderEmitsRetrySuccessAndFallbackThroughThePort(): void
    {
        $meter = new CounterMeter();
        $provider = new ResilientSecretsProvider(
            new FlakyInnerSecrets(2, 'secret-value'),
            maxAttempts: 3,
            backoffSeconds: 0.0,
            metrics: new MeterConfigMetrics($meter),
        );

        self::assertSame('secret-value', $provider->get('db.password'));

        $snap = $meter->snapshot();
        // Two failed attempts that had retry budget left -> two retry events.
        self::assertSame(2, $snap['zef.config.secrets.retries.total|{"key":"db.password","provider":"FlakyInnerSecrets"}']['count']);
        // Third attempt succeeded -> one resolved event for the provider call.
        self::assertSame(1, $snap['zef.config.secrets.success.total|{"provider":"FlakyInnerSecrets"}']['count']);
        // No fallback happened.
        self::assertArrayNotHasKey('zef.config.secrets.fallback.total|{"provider":"FlakyInnerSecrets"}', $snap);
    }

    public function testProviderLabelsAnonymousClassesSafely(): void
    {
        $meter = new CounterMeter();
        $inner = new class implements SecretsProviderInterface {
            #[\Override]
            public function get(string $key): ?string
            {
                return null; // unknown key — provider answered without a value
            }
        };
        $provider = new ResilientSecretsProvider($inner, metrics: new MeterConfigMetrics($meter));
        $provider->get('k');

        $series = array_values(array_filter(
            $meter->snapshot(),
            static fn (string $k): bool => str_starts_with($k, 'zef.config.secrets.success.total|'),
            ARRAY_FILTER_USE_KEY,
        ));
        self::assertCount(1, $series);
        // Anonymous class short names contain spaces/braces -> collapse to [other].
        self::assertSame('[other]', $series[0]['attributes']['provider']);
    }

    public function testFallbackEmitsEventAndRetryBudgetStillCounted(): void
    {
        $meter = new CounterMeter();
        $resilient = new ResilientSecretsProvider(
            new SucceedsThenFailsSecrets(1, 'first-good'),
            maxAttempts: 2,
            backoffSeconds: 0.0,
            metrics: new MeterConfigMetrics($meter),
        );

        // First read succeeds and seeds the known-good cache.
        self::assertSame('first-good', $resilient->get('db.password'));
        // Second read: every attempt fails, budget exhausts, stale value serves.
        self::assertSame('first-good', $resilient->get('db.password'));

        $snap = $meter->snapshot();
        self::assertSame(1, $snap['zef.config.secrets.success.total|{"provider":"SucceedsThenFailsSecrets"}']['count']);
        self::assertSame(1, $snap['zef.config.secrets.retries.total|{"key":"db.password","provider":"SucceedsThenFailsSecrets"}']['count']);
        $fallback = $snap['zef.config.secrets.fallback.total|{"provider":"SucceedsThenFailsSecrets"}'] ?? null;
        self::assertNotNull($fallback, 'stale fallback must be counted');
        self::assertSame(1, $fallback['count']);
    }

    public function testNullMetricsKeepsProviderFullyFunctional(): void
    {
        $provider = new ResilientSecretsProvider(
            new StaticInnerSecrets('v'),
            maxAttempts: 2,
            backoffSeconds: 0.0,
            metrics: null,
        );
        self::assertSame('v', $provider->get('any'));
    }

    public function testSecretValuesNeverAppearAsLabels(): void
    {
        $meter = new CounterMeter();
        $secret = 'super-secret-value';
        $provider = new ResilientSecretsProvider(
            new FlakyInnerSecrets(1, $secret),
            maxAttempts: 2,
            backoffSeconds: 0.0,
            metrics: new MeterConfigMetrics($meter),
        );
        $provider->get('db.password');

        foreach (array_keys($meter->snapshot()) as $seriesKey) {
            self::assertStringNotContainsString($secret, $seriesKey);
        }
    }
}

// ---- named fixtures (provider label = short class name) ---------------------

/**
 * Inner provider that throws for the first N calls, then answers a fixed
 * value forever.
 *
 * @internal
 */
final class FlakyInnerSecrets implements SecretsProviderInterface
{
    private int $left;

    public function __construct(int $failures, private readonly string $value)
    {
        $this->left = $failures;
    }

    #[\Override]
    public function get(string $key): string
    {
        if ($this->left > 0) {
            --$this->left;

            throw new \RuntimeException('transient failure');
        }

        return $this->value;
    }
}

/**
 * Inner provider that always answers a fixed value.
 *
 * @internal
 */
final class StaticInnerSecrets implements SecretsProviderInterface
{
    public function __construct(private readonly string $value) {}

    #[\Override]
    public function get(string $key): string
    {
        return $this->value;
    }
}

/**
 * Inner provider that answers a fixed value for the first N calls, then
 * fails forever — the stale-fallback scenario.
 *
 * @internal
 */
final class SucceedsThenFailsSecrets implements SecretsProviderInterface
{
    private int $successesLeft;

    public function __construct(int $successes, private readonly string $value)
    {
        $this->successesLeft = $successes;
    }

    #[\Override]
    public function get(string $key): string
    {
        if ($this->successesLeft > 0) {
            --$this->successesLeft;

            return $this->value;
        }

        throw new \RuntimeException('provider down');
    }
}
