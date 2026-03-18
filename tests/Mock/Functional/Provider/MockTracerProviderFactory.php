<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use OpenTelemetry\SDK\Trace\{
    NoopTracerProvider,
    TracerProviderInterface
};

class MockTracerProviderFactory implements TracerProviderFactoryInterface
{
    public function create(iterable $processors = [], iterable $exporters = []): TracerProviderInterface
    {
        return new NoopTracerProvider;
    }
}
