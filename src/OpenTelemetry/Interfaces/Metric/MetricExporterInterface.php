<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric;

use OpenTelemetry\SDK\Metrics\MetricExporterInterface as SdkMetricExporterInterface;

/**
 * Marker interface for custom metric exporters registered in the bundle.
 *
 * Implement this interface to have your exporter automatically discovered
 * and injected into MeterProvider via DI autoconfiguration.
 * Each exporter is automatically wrapped in an ExportingReader.
 *
 * {@see getSupportedInstrumentation()} controls which instrumentation scopes
 * (by name) this exporter will receive metrics from.
 * Return an empty array to receive metrics from all instrumentations.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.metric_exporter
 */
interface MetricExporterInterface extends SdkMetricExporterInterface
{
    /**
     * Returns a list of instrumentation scope names this exporter handles.
     * An empty array means the exporter receives metrics from all instrumentations.
     *
     * Instrumentation scope name corresponds to the name passed to CachedInstrumentation
     * (e.g. 'danilovl/open-telemetry').
     *
     * @return array<string>
     */
    public function getSupportedInstrumentation(): array;

    /**
     * Returns the priority of this exporter.
     * Higher value means the exporter's reader is added to the provider earlier.
     * Default: 0.
     */
    public function getPriority(): int;
}
