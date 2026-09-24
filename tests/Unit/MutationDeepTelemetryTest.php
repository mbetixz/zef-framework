<?php

declare(strict_types=1);

/*
 * Zef Framework v2.14.1 — Mutation deep-dive round 2: telemetry pipeline.
 *
 * Targets the escaped mutants reported for Telemetry (environment
 * construction with strict clamps, delivery queueing/draining, lifecycle
 * counters, shutdown idempotency) with capturing fakes and reflection
 * probes so every mutant produces an observable difference.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\OtlpExporterFactory;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\Tracer;

/**
 * Capturing metric exporter used by the telemetry tests.
 */
final class CapturingMetricExporter implements MetricExporterInterface
{
    /** @var list<array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}>> */
    public array $calls = [];

    public int $shutdowns = 0;

    public function __construct(private readonly bool $throwOnExport = false) {}

    public function exportMetrics(array $metrics): void
    {
        if ($this->throwOnExport) {
            throw new \RuntimeException('metric export failed');
        }
        $this->calls[] = $metrics;
    }

    public function shutdown(): void
    {
        ++$this->shutdowns;
    }
}

/**
 * Capturing log exporter used by the telemetry tests.
 */
final class CapturingLogExporter implements LogExporterInterface
{
    /** @var list<list<LogRecord>> */
    public array $batches = [];

    public int $shutdowns = 0;

    public function __construct(private readonly bool $throwOnExport = false) {}

    public function exportLogs(array $records): void
    {
        if ($this->throwOnExport) {
            throw new \RuntimeException('log export failed');
        }
        $this->batches[] = $records;
    }

    public function shutdown(): void
    {
        ++$this->shutdowns;
    }
}

/**
 * Implements BOTH exporter ports so shutdown de-duplication can be probed.
 */
final class DualExporter implements MetricExporterInterface, LogExporterInterface
{
    public int $shutdowns = 0;

    public function exportMetrics(array $metrics): void {}

    public function exportLogs(array $records): void {}

    public function shutdown(): void
    {
        ++$this->shutdowns;
    }
}

/**
 * @internal
 */
final class MutationDeepTelemetryTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'ZEF_OTEL_ENABLED',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT',
            'ZEF_OTEL_EXPORT_TIMEOUT_MS',
            'ZEF_OTEL_MAX_QUEUE',
            'ZEF_OTEL_BATCH_SIZE',
            'ZEF_OTEL_RETRY_ATTEMPTS',
            'ZEF_OTEL_RETRY_DELAY_MS',
            'ZEF_OTEL_RETRY_DELAY_CAP_MS',
            'ZEF_OTEL_SHUTDOWN_DRAIN_MS',
            'ZEF_OTEL_SERVICE_NAME',
        ] as $name) {
            \putenv($name);
        }
    }

    public function testDisabledByDefaultAndInMemory(): void
    {
        $telemetry = Telemetry::fromEnvironment();
        self::assertFalse($telemetry->isEnabled());
        self::assertTrue($telemetry->isInMemoryExporter());
    }

    public function testEndpointIsTrimmedAndDrivesOtlpExporter(): void
    {
        \putenv('ZEF_OTEL_ENABLED=1');
        \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT= http://collector:4318 ');
        \putenv('ZEF_OTEL_SERVICE_NAME=svc-probe');
        $telemetry = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
        self::assertTrue($telemetry->isEnabled());
        self::assertFalse($telemetry->isInMemoryExporter());
        $exporter = $this->reflectedExporter($telemetry);
        self::assertSame('http://collector:4318', new \ReflectionProperty(OtlpHttpJsonExporter::class, 'endpoint')->getValue($exporter));
        self::assertSame(500, new \ReflectionProperty(OtlpHttpJsonExporter::class, 'timeoutMs')->getValue($exporter), 'Default timeout must be exactly 500 ms.');
        $resource = new \ReflectionProperty(OtlpHttpJsonExporter::class, 'resource')->getValue($exporter);
        assert(is_array($resource));
        self::assertSame('svc-probe', $resource['service.name']);
        self::assertSame('zef-observability', $resource['telemetry.sdk.name']);
        self::assertSame('php', $resource['telemetry.sdk.language']);
    }

    public function testStrictEnvironmentRangesAcceptBoundariesOnly(): void
    {
        // Out-of-range values are rejected on BOTH sides for every variable;
        // in-range boundary values (min/max) are accepted.
        $this->assertStrictRange('http://collector:4318', [
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS', 0, 1, 10000],
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS', 1, 1, 10000],
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS', 10000, 1, 10000],
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS', 10001, 1, 10000],
            ['ZEF_OTEL_MAX_QUEUE', 0, 1, 8192],
            ['ZEF_OTEL_MAX_QUEUE', 8193, 1, 8192],
            ['ZEF_OTEL_BATCH_SIZE', 0, 1, 8192],
            ['ZEF_OTEL_BATCH_SIZE', 8193, 1, 8192],
            ['ZEF_OTEL_RETRY_ATTEMPTS', -1, 0, 10],
            ['ZEF_OTEL_RETRY_ATTEMPTS', 11, 0, 10],
            ['ZEF_OTEL_RETRY_DELAY_MS', -1, 0, 10000],
            ['ZEF_OTEL_RETRY_DELAY_MS', 10001, 0, 10000],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS', -1, 0, 60000],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS', 60001, 0, 60000],
            ['ZEF_OTEL_SHUTDOWN_DRAIN_MS', -1, 0, 60000],
            ['ZEF_OTEL_SHUTDOWN_DRAIN_MS', 60001, 0, 60000],
        ]);
    }

    public function testNonIntegerEnvironmentValuesAreRejectedInStrictMode(): void
    {
        \putenv('ZEF_OTEL_ENABLED=1');
        \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
        foreach ([
            'ZEF_OTEL_EXPORT_TIMEOUT_MS',
            'ZEF_OTEL_MAX_QUEUE',
            'ZEF_OTEL_BATCH_SIZE',
            'ZEF_OTEL_RETRY_ATTEMPTS',
            'ZEF_OTEL_RETRY_DELAY_MS',
            'ZEF_OTEL_RETRY_DELAY_CAP_MS',
            'ZEF_OTEL_SHUTDOWN_DRAIN_MS',
        ] as $name) {
            \putenv($name . '=not-an-int');

            try {
                Telemetry::fromEnvironment(null, false);
                self::fail("{$name}=not-an-int must be rejected in strict mode.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($name, $e->getMessage());
            }
            \putenv($name);
        }
    }

    public function testBatchSizeCannotExceedQueue(): void
    {
        \putenv('ZEF_OTEL_ENABLED=1');
        \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
        \putenv('ZEF_OTEL_MAX_QUEUE=1024');
        \putenv('ZEF_OTEL_BATCH_SIZE=1024');
        self::assertInstanceOf(Telemetry::class, Telemetry::fromEnvironment(null, false));
        \putenv('ZEF_OTEL_BATCH_SIZE=1025');
        $this->expectException(\InvalidArgumentException::class);
        Telemetry::fromEnvironment(null, false);
    }

    public function testEndpointValidationMatrix(): void
    {
        \putenv('ZEF_OTEL_ENABLED=1');
        foreach ([
            'ftp://collector:4318' => 'absolute HTTP/HTTPS URI',
            'collector:4318' => 'absolute HTTP/HTTPS URI',
            '/relative/only' => 'absolute HTTP/HTTPS URI',
            'http://user:pass@collector:4318' => 'must not contain embedded credentials',
            'HTTPS://collector:4318' => null, // valid: scheme is case-insensitive
        ] as $endpoint => $expectedFragment) {
            \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=' . $endpoint);

            try {
                $telemetry = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
                if ($expectedFragment !== null) {
                    self::fail("Endpoint '{$endpoint}' must be rejected.");
                }
                self::assertFalse($telemetry->isInMemoryExporter(), "Endpoint '{$endpoint}' is valid.");
            } catch (\InvalidArgumentException $e) {
                self::assertNotNull($expectedFragment, "Endpoint '{$endpoint}' must be accepted: " . $e->getMessage());
                self::assertStringContainsString($expectedFragment, $e->getMessage());
            }
            \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');
        }
    }

    public function testEnabledConstructorDefaultRecordsLogs(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        // Enabled defaults to true — the constructor must not need it spelled out.
        $telemetry = $this->telemetry($metric, $log);
        $telemetry->recordLog('info', 'hello', ['k' => 'v']);
        $telemetry->flush();
        self::assertCount(1, $log->batches, 'Enabled telemetry (default ctor param) must deliver logs.');
        self::assertCount(1, $metric->calls, 'Flush must deliver the meter snapshot.');
    }

    public function testRecordLogCapsAt256RecordsAndNormalizesSeverity(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = $this->telemetry($metric, $log);
        for ($i = 0; $i < 300; ++$i) {
            $telemetry->recordLog('warning', 'log-' . $i, ['idx' => $i]);
        }
        $telemetry->flush();
        self::assertCount(1, $log->batches);
        self::assertCount(256, $log->batches[0], 'recordLog must cap the in-memory buffer at exactly 256 records.');
        self::assertSame('WARNING', $log->batches[0][0]->severity);
        self::assertSame('log-0', $log->batches[0][0]->body);
        self::assertSame(['idx' => 0], $log->batches[0][0]->attributes);
        self::assertSame('log-255', $log->batches[0][255]->body, 'The cap keeps the FIRST 256 records.');
    }

    public function testDisabledTelemetryIsInert(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = new Telemetry(new NoopTracer(), new CounterMeter(), new BatchSpanProcessor(new InMemorySpanExporter()), $metric, $log, false);
        $telemetry->recordLog('info', 'dropped');
        $telemetry->flush();
        self::assertCount(0, $log->batches, 'Disabled telemetry must not deliver logs.');
        self::assertCount(0, $metric->calls, 'Disabled telemetry must not deliver metrics.');
        self::assertFalse($telemetry->isEnabled());
    }

    public function testFlushDeliversLifecycleCounterOnceWithFlushAttribute(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = $this->telemetry($metric, $log);
        $telemetry->recordLog('info', 'x');
        $telemetry->flush();
        self::assertCount(1, $metric->calls);
        $entry = $this->lifecycleEntry($metric->calls[0]);
        self::assertSame(1, $entry['count'], 'A single flush increments the lifecycle counter exactly once.');
        self::assertSame(['event.name' => 'telemetry.flush'], $entry['attributes']);
    }

    public function testShutdownIsIdempotentAndSignalsExportersExactlyOnce(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = $this->telemetry($metric, $log);
        $telemetry->recordLog('info', 'x');
        $telemetry->shutdown();
        $telemetry->recordLog('info', 'after-shutdown');
        $telemetry->flush();
        $telemetry->shutdown();
        self::assertCount(1, $metric->calls, 'Second shutdown must be a no-op (flag latched).');
        self::assertCount(1, $log->batches, 'Recording after shutdown must be ignored.');
        self::assertSame(1, $metric->shutdowns);
        self::assertSame(1, $log->shutdowns);
        // The only delivered snapshot comes from shutdown(): it carries the
        // flush counter incremented by shutdown itself (count 1, no prior
        // flush) plus a dedicated shutdown event (count 1).
        $snap = $metric->calls[0];
        $flushEntry = $this->lifecycleEntry($snap);
        self::assertSame(1, $flushEntry['count']);
        $shutdownKey = 'zef.lifecycle.events.total|' . \json_encode(['event.name' => 'telemetry.shutdown']);
        self::assertArrayHasKey($shutdownKey, $snap);
        self::assertSame(1, $snap[$shutdownKey]['count']);
    }

    public function testSharedExporterInstanceIsShutdownOnlyOnce(): void
    {
        $shared = new DualExporter();
        $telemetry = new Telemetry(new NoopTracer(), new CounterMeter(), new BatchSpanProcessor(new InMemorySpanExporter()), $shared, $shared);
        $telemetry->shutdown();
        self::assertSame(1, $shared->shutdowns, 'The same exporter used for metrics and logs must be shut down exactly once.');
    }

    public function testDistinctExportersAreBothShutdownOnce(): void
    {
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = $this->telemetry($metric, $log);
        $telemetry->shutdown();
        self::assertSame(1, $metric->shutdowns);
        self::assertSame(1, $log->shutdowns);
    }

    public function testExportFailuresAreSwallowedAndDoNotBreakShutdown(): void
    {
        $metric = new CapturingMetricExporter(true);
        $log = new CapturingLogExporter(true);
        $telemetry = $this->telemetry($metric, $log);
        $telemetry->recordLog('info', 'x');
        $telemetry->flush();
        $telemetry->shutdown();
        self::assertSame([], $metric->calls, 'Throwing exports must be swallowed.');
        self::assertSame([], $log->batches);
        self::assertSame(1, $metric->shutdowns, 'Exporter shutdown still runs after export failures.');
        self::assertSame(1, $log->shutdowns);
    }

    public function testShutdownFlushesPendingSpansThroughProcessor(): void
    {
        $inMemory = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($inMemory);
        $metric = new CapturingMetricExporter();
        $log = new CapturingLogExporter();
        $telemetry = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, $metric, $log);
        $span = $telemetry->startSpan('probe.operation');
        $span->end();
        self::assertCount(0, $inMemory->spans(), 'Ended spans stay buffered until shutdown/flush.');
        $telemetry->shutdown();
        self::assertCount(1, $inMemory->spans(), 'Telemetry shutdown must flush pending spans through the processor.');
    }

    public function testExtractForwardsTraceStateOnlyWhenProvided(): void
    {
        $telemetry = new Telemetry(new NoopTracer(), new CounterMeter(), new BatchSpanProcessor(new InMemorySpanExporter()));
        $context = $telemetry->extract('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertInstanceOf(SpanContext::class, $context);
        self::assertNull($context->traceState, 'Empty trace state must be forwarded as null, not as an empty string.');
        $withState = $telemetry->extract('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=1');
        self::assertInstanceOf(SpanContext::class, $withState);
        self::assertSame('vendor=1', $withState->traceState);
        self::assertNull($telemetry->extract('not-a-traceparent'));
    }

    // ------------------------------------------------------------------
    // fromEnvironment: strict clamps, defaults, endpoint validation
    // ------------------------------------------------------------------

    private function reflectedExporter(Telemetry $telemetry): OtlpHttpJsonExporter
    {
        $exporter = new \ReflectionProperty(Telemetry::class, 'metricExporter')->getValue($telemetry);
        assert($exporter instanceof OtlpHttpJsonExporter);

        return $exporter;
    }

    /**
     * @param list<array{string,int,int,int}> $vars
     */
    private function assertStrictRange(string $endpoint, array $vars): void
    {
        \putenv('ZEF_OTEL_ENABLED=1');
        \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=' . $endpoint);
        foreach ($vars as [$name, $value, $min, $max]) {
            \putenv($name . '=' . $value);

            try {
                Telemetry::fromEnvironment(null, false);
                self::assertTrue($value >= $min && $value <= $max, "{$name}={$value} (range {$min}..{$max}) should be accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertTrue($value < $min || $value > $max, "{$name}={$value} (range {$min}..{$max}) should be rejected");
                self::assertStringContainsString($name, $e->getMessage());
            }
            \putenv($name);
        }
    }

    // ------------------------------------------------------------------
    // recordLog / flush / shutdown lifecycle
    // ------------------------------------------------------------------

    private function telemetry(
        CapturingMetricExporter $metricExporter,
        CapturingLogExporter $logExporter,
        ?BatchSpanProcessor $processor = null,
        ?Tracer $tracer = null,
    ): Telemetry {
        $processor ??= new BatchSpanProcessor(new InMemorySpanExporter());

        return new Telemetry($tracer ?? new NoopTracer(), new CounterMeter(), $processor, $metricExporter, $logExporter);
    }

    /**
     * @param array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}> $snapshot
     *
     * @return array{count:float|int,sum:float,attributes:array<string,mixed>}
     */
    private function lifecycleEntry(array $snapshot): array
    {
        foreach ($snapshot as $key => $entry) {
            if ($key === 'zef.lifecycle.events.total' || str_starts_with((string) $key, 'zef.lifecycle.events.total|')) {
                return $entry;
            }
        }
        self::fail('Lifecycle counter missing from the meter snapshot.');
    }
}
