<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric\MetricExporterInterface as BundleMetricExporterInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MeterProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\Exporter\InstrumentationFilteringMetricExporter;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporterFactory;
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface,
    MetricExporterInterface,
    MetricReaderInterface
};
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

final readonly class DefaultMeterProviderFactory implements MeterProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, MetricExporterInterface> $exporters
     * @param iterable<int, MetricReaderInterface>   $readers
     */
    public function create(iterable $exporters = [], iterable $readers = []): MeterProviderInterface
    {
        $resource = $this->resourceInfoFactory->createResource();
        $defaultExporter = (new MetricExporterFactory)->create();
        $defaultReader = new ExportingReader($defaultExporter);

        $builder = MeterProvider::builder()
            ->addReader($defaultReader)
            ->setResource($resource);

        foreach ($exporters as $metricExporter) {
            $wrappedExporter = $metricExporter instanceof BundleMetricExporterInterface
                ? new InstrumentationFilteringMetricExporter($metricExporter)
                : $metricExporter;

            $builder->addReader(new ExportingReader($wrappedExporter));
        }

        foreach ($readers as $reader) {
            $builder->addReader($reader);
        }

        return $builder->build();
    }
}
