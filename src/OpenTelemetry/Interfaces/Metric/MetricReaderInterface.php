<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric;

use OpenTelemetry\SDK\Metrics\MetricReaderInterface as SdkMetricReaderInterface;

/**
 * Marker interface for custom metric readers registered in the bundle.
 *
 * Implement this interface to have your reader automatically discovered
 * and injected into MeterProvider via DI autoconfiguration.
 *
 * Use this when you need full control over the reader (e.g. a pull-based reader
 * or a reader that wraps a custom exporter internally).
 * For simple exporter registration prefer {@see MetricExporterInterface}.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.metric_reader
 */
interface MetricReaderInterface extends SdkMetricReaderInterface
{
    /**
     * Returns the priority of this reader.
     * Higher value means the reader is added to the provider earlier.
     * Default: 0.
     */
    public function getPriority(): int;
}
