<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Factory for creating a TextMapPropagator used for distributed trace context propagation.
 *
 * Implement this interface to supply a custom propagator (e.g. B3, Jaeger, or a composite one)
 * instead of the default W3C TraceContext propagator.
 * The bundle calls {@see create()} once and registers the result as the global propagator
 * via {@see \OpenTelemetry\API\Globals::registerInitializer()}.
 */
interface PropagatorFactoryInterface
{
    public function create(): TextMapPropagatorInterface;
}
