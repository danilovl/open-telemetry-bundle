<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MeterProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporterFactory;
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface
};
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

final readonly class DefaultMeterProviderFactory implements MeterProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    public function create(): MeterProviderInterface
    {
        $resource = $this->resourceInfoFactory->createResource();

        $exporter = (new MetricExporterFactory)->create();
        $reader = new ExportingReader($exporter);

        $meterProvider = MeterProvider::builder()
            ->addReader($reader)
            ->setResource($resource)
            ->build();

        return $meterProvider;
    }
}
