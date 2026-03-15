<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\SDK\Logs\LoggerProviderInterface;

interface LoggerProviderFactoryInterface
{
    public function create(): LoggerProviderInterface;
}
