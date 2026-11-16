<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\Exporter\InstrumentationFilteringSpanExporter;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace\TraceSpanExporterInterface;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\{
    ExporterFactory,
    NoopTracerProvider,
    SamplerFactory,
    SpanExporterInterface,
    SpanProcessorFactory,
    SpanProcessorInterface,
    TracerProvider,
    TracerProviderInterface
};
use Throwable;

/** Honours OTEL_SDK_DISABLED, OTEL_TRACES_SAMPLER, OTEL_TRACES_EXPORTER and OTEL_PHP_TRACES_PROCESSOR. */
final readonly class DefaultTracerProviderFactory implements TracerProviderFactoryInterface
{
    use LogsMessagesTrait;

    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, SpanProcessorInterface> $processors
     * @param iterable<int, SpanExporterInterface> $exporters
     */
    public function create(iterable $processors = [], iterable $exporters = []): TracerProviderInterface
    {
        if (Sdk::isDisabled()) {
            return new NoopTracerProvider;
        }

        $builder = TracerProvider::builder()
            ->setResource($this->resourceInfoFactory->createResource())
            ->setSampler(new SamplerFactory()->create());

        $defaultExporter = $this->createDefaultExporter();
        if ($defaultExporter !== null) {
            $builder->addSpanProcessor(new SpanProcessorFactory()->create($defaultExporter));
        }

        foreach ($processors as $spanProcessor) {
            $builder->addSpanProcessor($spanProcessor);
        }

        foreach ($exporters as $spanExporter) {
            $wrappedExporter = $spanExporter instanceof TraceSpanExporterInterface
                ? new InstrumentationFilteringSpanExporter($spanExporter)
                : $spanExporter;

            $builder->addSpanProcessor(new BatchSpanProcessor($wrappedExporter, SystemClock::create()));
        }

        return $builder->build();
    }

    private function createDefaultExporter(): ?SpanExporterInterface
    {
        try {
            return new ExporterFactory()->create();
        } catch (Throwable $throwable) {
            self::logWarning('Unable to create the default span exporter: ' . $throwable->getMessage());

            return null;
        }
    }
}
