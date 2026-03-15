<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Log;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\LoggerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporterFactory;
use OpenTelemetry\SDK\Logs\{
    LoggerProvider,
    LoggerProviderInterface
};
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;

final readonly class DefaultLoggerProviderFactory implements LoggerProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    public function create(): LoggerProviderInterface
    {
        $resource = $this->resourceInfoFactory->createResource();

        $exporter = (new LogsExporterFactory)->create();
        $processor = new SimpleLogRecordProcessor($exporter);

        $loggerProvider = LoggerProvider::builder()
            ->addLogRecordProcessor($processor)
            ->setResource($resource)
            ->build();

        return $loggerProvider;
    }
}
