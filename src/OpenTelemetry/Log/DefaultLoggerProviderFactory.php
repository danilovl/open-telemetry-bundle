<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Log;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log\LogRecordExporterInterface as BundleLogRecordExporterInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\LoggerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\Exporter\InstrumentationFilteringLogRecordExporter;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\SDK\Logs\{
    ExporterFactory,
    LoggerProvider,
    LoggerProviderInterface,
    LogRecordExporterInterface,
    LogRecordProcessorFactory,
    LogRecordProcessorInterface,
    NoopLoggerProvider
};
use OpenTelemetry\SDK\Logs\Exporter\NoopExporter;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Sdk;
use Throwable;

/** Honours OTEL_SDK_DISABLED, OTEL_LOGS_EXPORTER and OTEL_PHP_LOGS_PROCESSOR (batch by default). */
final readonly class DefaultLoggerProviderFactory implements LoggerProviderFactoryInterface
{
    use LogsMessagesTrait;

    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, LogRecordProcessorInterface> $processors
     * @param iterable<int, LogRecordExporterInterface>  $exporters
     */
    public function create(iterable $processors = [], iterable $exporters = []): LoggerProviderInterface
    {
        if (Sdk::isDisabled()) {
            return new NoopLoggerProvider;
        }

        $builder = LoggerProvider::builder()
            ->setResource($this->resourceInfoFactory->createResource());

        $defaultExporter = $this->createDefaultExporter();
        if ($defaultExporter !== null) {
            $builder->addLogRecordProcessor(new LogRecordProcessorFactory()->create($defaultExporter));
        }

        foreach ($processors as $processor) {
            $builder->addLogRecordProcessor($processor);
        }

        foreach ($exporters as $logExporter) {
            $wrappedExporter = $logExporter instanceof BundleLogRecordExporterInterface
                ? new InstrumentationFilteringLogRecordExporter($logExporter)
                : $logExporter;

            $builder->addLogRecordProcessor(new BatchLogRecordProcessor($wrappedExporter, SystemClock::create()));
        }

        return $builder->build();
    }

    private function createDefaultExporter(): ?LogRecordExporterInterface
    {
        try {
            $exporter = new ExporterFactory()->create();
        } catch (Throwable $throwable) {
            self::logWarning('Unable to create the default log record exporter: ' . $throwable->getMessage());

            return null;
        }

        return $exporter instanceof NoopExporter ? null : $exporter;
    }
}
