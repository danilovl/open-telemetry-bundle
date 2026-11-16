<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric\MetricExporterInterface as BundleMetricExporterInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MeterProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\Exporter\InstrumentationFilteringMetricExporter;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Configuration\{
    Configuration,
    Variables
};
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface,
    MetricExporterInterface,
    MetricReaderInterface,
    NoopMeterProvider
};
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\{
    Registry,
    Sdk
};
use Throwable;

/** Honours OTEL_SDK_DISABLED and OTEL_METRICS_EXPORTER. */
final readonly class DefaultMeterProviderFactory implements MeterProviderFactoryInterface
{
    use LogsMessagesTrait;

    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, MetricExporterInterface> $exporters
     * @param iterable<int, MetricReaderInterface>   $readers
     */
    public function create(iterable $exporters = [], iterable $readers = []): MeterProviderInterface
    {
        if (Sdk::isDisabled()) {
            return new NoopMeterProvider;
        }

        $builder = MeterProvider::builder()
            ->setResource($this->resourceInfoFactory->createResource());

        $defaultExporter = $this->createDefaultExporter();
        if ($defaultExporter !== null) {
            $builder->addReader(new ExportingReader($defaultExporter));
        }

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

    private function createDefaultExporter(): ?MetricExporterInterface
    {
        $exporters = Configuration::getList(Variables::OTEL_METRICS_EXPORTER);
        $exporterName = $exporters[0] ?? 'none';

        if ($exporterName === 'none') {
            return null;
        }

        try {
            return Registry::metricExporterFactory($exporterName)->create();
        } catch (Throwable $throwable) {
            self::logWarning(sprintf('Unable to create the default "%s" metric exporter: %s', $exporterName, $throwable->getMessage()));

            return null;
        }
    }
}
