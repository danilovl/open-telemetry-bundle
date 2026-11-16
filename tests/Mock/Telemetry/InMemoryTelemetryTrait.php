<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry;

use PHPUnit\Framework\Attributes\{
    After,
    Before
};

trait InMemoryTelemetryTrait
{
    protected InMemoryTelemetry $telemetry;

    #[Before]
    protected function installTelemetry(): void
    {
        $this->telemetry = InMemoryTelemetry::install();
    }

    #[After]
    protected function uninstallTelemetry(): void
    {
        $this->telemetry->uninstall();
    }
}
