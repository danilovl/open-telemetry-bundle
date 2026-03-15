<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\PropagatorFactoryInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PropagatorFactoryInterface::class)]
final class TraceContextPropagatorFactory implements PropagatorFactoryInterface
{
    public function create(): TextMapPropagatorInterface
    {
        return TraceContextPropagator::getInstance();
    }
}
