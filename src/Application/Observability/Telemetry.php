<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zef\Framework\Foundation\Env;

final class Telemetry
{
    /**
     * @var list<LogRecord>
     */
    private array $logs = [];

    /**
     * @var list<array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}>>
     */
    private array $metricDeliveryQueue = [];

    /**
     * @var list<list<LogRecord>>
     */
    private array $logDeliveryQueue = [];
    private bool $shutdown = false;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly MeterInterface $meter,
        private readonly BatchSpanProcessor $processor,
        private readonly ?MetricExporterInterface $metricExporter = null,
        private readonly ?LogExporterInterface $logExporter = null,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Bug fix #10: added $registerShutdownHook parameter.
     */
    public static function fromEnvironment(
        ?LoggerInterface $logger = null,
        bool $registerShutdownHook = true,
    ): self {
        $enabled = Env::bool('ZEF_OTEL_ENABLED', false);
        $logger ??= new NullLogger();
        if (!$enabled) {
            return new self(
                new NoopTracer(),
                new CounterMeter(),
                new BatchSpanProcessor(new InMemorySpanExporter()),
                null,
                null,
                false,
            );
        }
        $endpoint = trim(Env::string('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT'));
        if ($endpoint !== '') {
            self::validateEndpoint($endpoint);
        }
        $timeout = Env::int('ZEF_OTEL_EXPORT_TIMEOUT_MS', 500, 1, 10000, true);
        $queue = Env::int('ZEF_OTEL_MAX_QUEUE', 1024, 1, 8192, true);
        $batch = Env::int('ZEF_OTEL_BATCH_SIZE', 128, 1, $queue, true);
        Env::int('ZEF_OTEL_RETRY_ATTEMPTS', 2, 0, 10, true);
        Env::int('ZEF_OTEL_RETRY_DELAY_MS', 100, 0, 10000, true);
        Env::int('ZEF_OTEL_RETRY_DELAY_CAP_MS', 1000, 0, 60000, true);
        Env::int('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000, true);
        $resource = [
            'service.name' => Env::string('ZEF_OTEL_SERVICE_NAME', 'zef-application'),
            'telemetry.sdk.name' => 'zef-observability',
            'telemetry.sdk.language' => 'php',
        ];
        $exporter = $endpoint !== '' ? new OtlpHttpJsonExporter($endpoint, $resource, $timeout) : null;
        $spanExporter = $exporter ?? new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($spanExporter, $queue, $batch);
        $t = new self(new Tracer($processor), new CounterMeter(), $processor, $exporter, $exporter, true);
        if ($registerShutdownHook) {
            register_shutdown_function($t->shutdown(...));
        }

        return $t;
    }

    /** @param array<string,mixed> $attributes */
    public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface
    {
        return $this->tracer->startSpan($name, $attributes, $parent);
    }

    public function tracer(): TracerInterface
    {
        return $this->tracer;
    }

    public function meter(): MeterInterface
    {
        return $this->meter;
    }

    public function extract(string $traceParent, string $traceState = ''): ?SpanContext
    {
        return TraceContextPropagator::extract($traceParent, $traceState !== '' ? $traceState : null);
    }

    /** @param array<string,mixed> $attributes */
    public function recordLog(string $severity, string $body, array $attributes = []): void
    {
        if (!$this->enabled || $this->shutdown || count($this->logs) >= 256) {
            return;
        }
        $this->logs[] = new LogRecord(
            strtoupper($severity),
            TelemetrySanitizer::string($body),
            TelemetryClock::nowUnixNano(),
            TelemetrySanitizer::attributes($attributes),
        );
    }

    public function flush(): void
    {
        if (!$this->enabled || $this->shutdown) {
            return;
        }
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.flush']);
        $this->processor->flush();
        $this->enqueueDelivery();
        $this->drainDelivery();
    }

    public function shutdown(): void
    {
        if (!$this->enabled || $this->shutdown) {
            return;
        }
        // Set the flag FIRST so no hook can re-enter shutdown(); the final
        // lifecycle counters below are captured by enqueueDelivery() and
        // reach the exporter instead of being dead writes.
        $this->shutdown = true;
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.flush']);
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.shutdown']);

        try {
            $this->processor->shutdown();
        } catch (\Throwable) {
        }
        $this->enqueueDelivery();
        $deadline = microtime(true) + Env::int('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000) / 1000;
        while (
            ($this->metricDeliveryQueue !== [] || $this->logDeliveryQueue !== [])
            && microtime(true) < $deadline
        ) {
            $this->drainOne();
        }

        try {
            $this->metricExporter?->shutdown();
        } catch (\Throwable) {
        }

        try {
            if ($this->logExporter instanceof LogExporterInterface && $this->logExporter !== $this->metricExporter) {
                $this->logExporter->shutdown();
            }
        } catch (\Throwable) {
        }
        $this->logs = [];
        $this->metricDeliveryQueue = [];
        $this->logDeliveryQueue = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isInMemoryExporter(): bool
    {
        return $this->processor->isInMemoryExporter();
    }

    private function enqueueDelivery(): void
    {
        if ($this->metricExporter instanceof MetricExporterInterface && count($this->metricDeliveryQueue) < 1024) {
            $this->metricDeliveryQueue[] = $this->meter->snapshot();
        }
        if ($this->logs !== []) {
            $logs = $this->logs;
            $this->logs = [];
            if (count($this->logDeliveryQueue) < 1024) {
                $this->logDeliveryQueue[] = $logs;
            }
        }
    }

    private function drainDelivery(): void
    {
        while ($this->metricDeliveryQueue !== []) {
            $metrics = array_shift($this->metricDeliveryQueue);
            if (!$this->metricExporter instanceof MetricExporterInterface) {
                continue;
            }

            try {
                $this->metricExporter->exportMetrics($metrics);
            } catch (\Throwable) {
            }
        }
        while ($this->logDeliveryQueue !== []) {
            $logs = array_shift($this->logDeliveryQueue);
            if (!$this->logExporter instanceof LogExporterInterface) {
                continue;
            }

            try {
                $this->logExporter->exportLogs($logs);
            } catch (\Throwable) {
            }
        }
    }

    private function drainOne(): void
    {
        if ($this->metricDeliveryQueue !== [] && $this->metricExporter instanceof MetricExporterInterface) {
            $metrics = array_shift($this->metricDeliveryQueue);

            try {
                $this->metricExporter->exportMetrics($metrics);
            } catch (\Throwable) {
                return;
            }
        }
        if ($this->logDeliveryQueue !== [] && $this->logExporter instanceof LogExporterInterface) {
            $logs = array_shift($this->logDeliveryQueue);

            try {
                $this->logExporter->exportLogs($logs);
            } catch (\Throwable) {
            }
        }
    }

    private static function validateEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT must be an absolute HTTP/HTTPS URI.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT must not contain embedded credentials.');
        }
    }
}
