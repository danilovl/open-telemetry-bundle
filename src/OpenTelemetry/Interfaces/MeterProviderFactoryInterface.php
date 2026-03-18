<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Metrics\{
    MeterProviderInterface,
    MetricExporterInterface,
    MetricReaderInterface
};

/**
 * Factory for creating a configured MeterProvider instance.
 *
 * Implement this interface to provide a custom MeterProvider construction strategy
 * (e.g. with a specific exporter, resource, or view configuration).
 * The bundle calls {@see create()} once during container compilation and registers
 * the resulting provider as the application-wide MeterProvider.
 *
 * Exporters and readers collected via DI autoconfiguration are passed in;
 * each exporter is automatically wrapped in an ExportingReader before being added.
 */
interface MeterProviderFactoryInterface
{
    /**
     * @param iterable<int, MetricExporterInterface> $exporters each exporter is wrapped in ExportingReader automatically
     * @param iterable<int, MetricReaderInterface>   $readers
     */
    public function create(iterable $exporters = [], iterable $readers = []): MeterProviderInterface;
}
