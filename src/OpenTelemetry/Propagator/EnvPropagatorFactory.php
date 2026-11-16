<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\PropagatorFactoryInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PropagatorFactoryInterface::class)]
final class EnvPropagatorFactory implements PropagatorFactoryInterface
{
    public function create(): TextMapPropagatorInterface
    {
        return new PropagatorFactory()->create();
    }
}
