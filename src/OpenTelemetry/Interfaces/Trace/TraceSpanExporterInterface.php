<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Marker interface for custom span exporters registered in the bundle.
 *
 * Implement this interface to have your exporter automatically discovered
 * and injected into TracerProvider via DI autoconfiguration.
 *
 * {@see getSupportedInstrumentation()} controls which instrumentation scopes
 * (by name) this exporter will receive spans from.
 * Return an empty array to receive spans from all instrumentations.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.span_exporter
 */
interface TraceSpanExporterInterface extends SpanExporterInterface
{
    /**
     * Returns a list of instrumentation scope names this exporter handles.
     * An empty array means the exporter receives spans from all instrumentations.
     *
     * Instrumentation scope name corresponds to the name passed to CachedInstrumentation
     * (e.g. 'danilovl/open-telemetry').
     *
     * @return array<string>
     */
    public function getSupportedInstrumentation(): array;

    /**
     * Returns the priority of this exporter.
     * Higher value means the exporter is added to the provider earlier.
     * Default: 0.
     */
    public function getPriority(): int;
}
