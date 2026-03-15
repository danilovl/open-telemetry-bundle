<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

interface PropagatorFactoryInterface
{
    public function create(): TextMapPropagatorInterface;
}
