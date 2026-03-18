<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\Exporter;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface as SdkMetricExporterInterface;

/**
 * Decorator that filters metrics by instrumentation scope before delegating
 * to the inner {@see MetricExporterInterface}.
 *
 * If the inner exporter returns an empty array from {@see MetricExporterInterface::getSupportedInstrumentation()},
 * all metrics are passed through without filtering.
 */
final class InstrumentationFilteringMetricExporter implements SdkMetricExporterInterface
{
    public function __construct(private readonly MetricExporterInterface $inner) {}

    public function export(iterable $batch): bool
    {
        $supported = $this->inner->getSupportedInstrumentation();

        if ($supported === []) {
            return $this->inner->export($batch);
        }

        $items = is_array($batch) ? $batch : iterator_to_array($batch);

        $filtered = array_values(
            array_filter(
                $items,
                static fn (Metric $metric): bool => in_array(
                    $metric->instrumentationScope->getName(),
                    $supported,
                    true
                )
            )
        );

        return $this->inner->export($filtered);
    }

    public function shutdown(): bool
    {
        return $this->inner->shutdown();
    }
}
