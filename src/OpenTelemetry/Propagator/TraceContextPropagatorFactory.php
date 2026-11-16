<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\PropagatorFactoryInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/** Alias PropagatorFactoryInterface to this class to ignore OTEL_PROPAGATORS. */
final class TraceContextPropagatorFactory implements PropagatorFactoryInterface
{
    public function create(): TextMapPropagatorInterface
    {
        return TraceContextPropagator::getInstance();
    }
}
