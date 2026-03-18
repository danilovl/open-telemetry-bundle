<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Trace\{
    SpanExporterInterface,
    SpanProcessorInterface,
    TracerProviderInterface
};

/**
 * Factory for creating a configured TracerProvider instance.
 *
 * Implement this interface to provide a custom TracerProvider construction strategy
 * (e.g. with a specific sampler, resource, or id generator).
 * The bundle calls {@see create()} once during container compilation and registers
 * the resulting provider as the application-wide TracerProvider.
 *
 * Processors and exporters collected via DI autoconfiguration are passed in;
 * each exporter is automatically wrapped in a BatchSpanProcessor before being added.
 */
interface TracerProviderFactoryInterface
{
    /**
     * @param iterable<int, SpanProcessorInterface> $processors
     * @param iterable<int, SpanExporterInterface>  $exporters each exporter is wrapped in BatchSpanProcessor automatically
     */
    public function create(iterable $processors = [], iterable $exporters = []): TracerProviderInterface;
}
