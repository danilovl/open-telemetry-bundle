<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\LoggerProviderFactoryInterface;
use OpenTelemetry\SDK\Logs\{
    LoggerProviderInterface,
    NoopLoggerProvider
};

class MockLoggerProviderFactory implements LoggerProviderFactoryInterface
{
    public function create(): LoggerProviderInterface
    {
        return NoopLoggerProvider::getInstance();
    }
}
