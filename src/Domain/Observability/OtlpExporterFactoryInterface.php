<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the issue #36 exit ramp (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Default-exporter port (issue #36 exit ramp for the OtlpExporter carve-out):
 * the surface the Telemetry facade composes its default OTLP exporter through.
 *
 * The exporter contracts (SpanExporterInterface, MetricExporterInterface,
 * LogExporterInterface) already lived in this layer; what kept the carve-out
 * alive was the Telemetry facade hard-wiring the concrete Infrastructure
 * HTTP/JSON exporter inside its static factory. Typing the construction
 * against this port instead removes the last Application-to-Infrastructure
 * reference, and the default exporter becomes a wiring decision: the kernel
 * composition root registers the default implementation as an ordinary
 * container service, so applications can swap the whole exporter stack via
 * config without touching the facade.
 *
 * The return type is the intersection of the three exporter contracts because
 * the default exporter fans out into all three pipelines (spans via the batch
 * processor, metrics and logs via the delivery queues) from a single instance.
 */
interface OtlpExporterFactoryInterface
{
    /**
     * Build the default exporter for an OTLP/HTTP endpoint. The timeout is
     * clamped by the caller (1..10000 ms, mirroring the exporter contract).
     *
     * @param array<string,mixed> $resource resource attributes (service.name, telemetry.sdk.*)
     */
    public function create(
        string $endpoint,
        array $resource,
        int $timeoutMs,
    ): LogExporterInterface&MetricExporterInterface&SpanExporterInterface;
}
