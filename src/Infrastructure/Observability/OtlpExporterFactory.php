<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the issue #36 exit ramp (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Default Infrastructure adapter for the OtlpExporterFactoryInterface port:
 * builds the OTLP/HTTP-JSON exporter.
 *
 * The kernel composition root registers this class as the container default
 * for the port, which is what retires the OtlpExporter carve-out: the
 * Telemetry facade no longer references the concrete exporter, and the
 * default wiring is a config-level decision applications can override.
 */
final class OtlpExporterFactory implements OtlpExporterFactoryInterface
{
    #[\Override]
    public function create(
        string $endpoint,
        array $resource,
        int $timeoutMs,
    ): LogExporterInterface&MetricExporterInterface&SpanExporterInterface {
        return new OtlpHttpJsonExporter($endpoint, $resource, $timeoutMs);
    }
}
