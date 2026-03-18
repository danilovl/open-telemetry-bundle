<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Logs\{
    LoggerProviderInterface,
    LogRecordExporterInterface,
    LogRecordProcessorInterface
};

/**
 * Factory for creating a configured LoggerProvider instance.
 *
 * Implement this interface to provide a custom LoggerProvider construction strategy
 * (e.g. with a specific exporter, resource, or sampling configuration).
 * The bundle calls {@see create()} once during container compilation and registers
 * the resulting provider as the application-wide LoggerProvider.
 *
 * Processors and exporters collected via DI autoconfiguration are passed in;
 * each exporter is automatically wrapped in a SimpleLogRecordProcessor before being added.
 */
interface LoggerProviderFactoryInterface
{
    /**
     * @param iterable<int, LogRecordProcessorInterface> $processors
     * @param iterable<int, LogRecordExporterInterface>  $exporters each exporter is wrapped in SimpleLogRecordProcessor automatically
     */
    public function create(iterable $processors = [], iterable $exporters = []): LoggerProviderInterface;
}
