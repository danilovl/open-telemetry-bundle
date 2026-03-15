<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Trace\{
    SpanProcessorInterface,
    TracerProviderInterface
};

interface TracerProviderFactoryInterface
{
    /**
     * @param iterable<int, SpanProcessorInterface> $processors
     */
    public function create(iterable $processors = []): TracerProviderInterface;
}
