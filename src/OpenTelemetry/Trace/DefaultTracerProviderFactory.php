<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\Exporter\InstrumentationFilteringSpanExporter;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace\TraceSpanExporterInterface;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Trace\Sampler\{
    AlwaysOnSampler,
    ParentBased
};
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\{
    SpanExporterInterface,
    SpanProcessorInterface,
    TracerProvider,
    TracerProviderInterface
};

final readonly class DefaultTracerProviderFactory implements TracerProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    /**
     * @param iterable<int, SpanProcessorInterface> $processors
     * @param iterable<int, SpanExporterInterface> $exporters
     */
    public function create(iterable $processors = [], iterable $exporters = []): TracerProviderInterface
    {
        $resource = $this->resourceInfoFactory->createResource();
        $exporter = (new SpanExporterFactory)->create();
        $processor = new BatchSpanProcessor($exporter, SystemClock::create());

        $builder = TracerProvider::builder()
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler));

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
}
