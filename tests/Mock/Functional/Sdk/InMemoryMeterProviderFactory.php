<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MeterProviderFactoryInterface;
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface
};
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

final class InMemoryMeterProviderFactory implements MeterProviderFactoryInterface
{
    public readonly InMemoryExporter $exporter;

    public readonly ExportingReader $reader;

    public function __construct()
    {
        $this->exporter = new InMemoryExporter;
        $this->reader = new ExportingReader($this->exporter);
    }

    public function create(iterable $exporters = [], iterable $readers = []): MeterProviderInterface
    {
        return MeterProvider::builder()->addReader($this->reader)->build();
    }
}
