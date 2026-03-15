<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Metrics\MeterProviderInterface;

interface MeterProviderFactoryInterface
{
    public function create(): MeterProviderInterface;
}
