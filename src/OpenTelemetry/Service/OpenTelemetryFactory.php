<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Service;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\PropagatorFactoryInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;

final readonly class OpenTelemetryFactory
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private MeterProviderInterface $meterProvider,
        private LoggerProviderInterface $loggerProvider,
        private PropagatorFactoryInterface $propagatorFactory,
    ) {}

    public function initializeSdk(): void
    {
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->setMeterProvider($this->meterProvider)
            ->setLoggerProvider($this->loggerProvider)
            ->setPropagator($this->propagatorFactory->create())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }
}
