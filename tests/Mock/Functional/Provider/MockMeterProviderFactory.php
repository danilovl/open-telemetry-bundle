<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MeterProviderFactoryInterface;
use OpenTelemetry\SDK\Metrics\{
    MeterProviderInterface,
    NoopMeterProvider
};

class MockMeterProviderFactory implements MeterProviderFactoryInterface
{
    public function create(iterable $exporters = [], iterable $readers = []): MeterProviderInterface
    {
        return new NoopMeterProvider;
    }
}
