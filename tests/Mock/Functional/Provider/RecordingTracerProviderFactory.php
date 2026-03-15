<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Override;

class RecordingTracerProviderFactory implements TracerProviderFactoryInterface
{
    private RecordingTracerProvider $provider;

    public function __construct()
    {
        $this->provider = new RecordingTracerProvider;
    }

    #[Override]
    public function create(iterable $processors = []): TracerProviderInterface
    {
        return $this->provider;
    }

    public function getProvider(): RecordingTracerProvider
    {
        return $this->provider;
    }
}
