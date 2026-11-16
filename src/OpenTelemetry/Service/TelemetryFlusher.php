<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Service;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/** Flush only: in worker runtimes the providers must survive between requests. */
class TelemetryFlusher
{
    public function flush(): void
    {
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProviderInterface) {
            $tracerProvider->forceFlush();
        }

        $meterProvider = Globals::meterProvider();
        if ($meterProvider instanceof MeterProviderInterface) {
            $meterProvider->forceFlush();
        }

        $loggerProvider = Globals::loggerProvider();
        if ($loggerProvider instanceof LoggerProviderInterface) {
            $loggerProvider->forceFlush();
        }
    }
}
