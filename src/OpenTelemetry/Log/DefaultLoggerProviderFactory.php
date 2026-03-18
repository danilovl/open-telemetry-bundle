<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Log;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log\LogRecordExporterInterface as BundleLogRecordExporterInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\LoggerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\Exporter\InstrumentationFilteringLogRecordExporter;
use OpenTelemetry\Contrib\Otlp\LogsExporterFactory;
use OpenTelemetry\SDK\Logs\{
    LoggerProvider,
    LoggerProviderInterface,
    LogRecordExporterInterface,
    LogRecordProcessorInterface
};
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;

final readonly class DefaultLoggerProviderFactory implements LoggerProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, LogRecordProcessorInterface> $processors
     * @param iterable<int, LogRecordExporterInterface>  $exporters
     */
    public function create(iterable $processors = [], iterable $exporters = []): LoggerProviderInterface
    {
        $resource = $this->resourceInfoFactory->createResource();
        $exporter = (new LogsExporterFactory)->create();
        $defaultProcessor = new SimpleLogRecordProcessor($exporter);

        $builder = LoggerProvider::builder()
            ->addLogRecordProcessor($defaultProcessor)
            ->setResource($resource);

        foreach ($processors as $processor) {
            $builder->addLogRecordProcessor($processor);
        }

        foreach ($exporters as $logExporter) {
            $wrappedExporter = $logExporter instanceof BundleLogRecordExporterInterface
                ? new InstrumentationFilteringLogRecordExporter($logExporter)
                : $logExporter;

            $builder->addLogRecordProcessor(new SimpleLogRecordProcessor($wrappedExporter));
        }

        return $builder->build();
    }
}
