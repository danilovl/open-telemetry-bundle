<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Trace\Sampler\{
    AlwaysOnSampler,
    ParentBased
};
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\{
    TracerProvider,
    TracerProviderInterface
};

final readonly class DefaultTracerProviderFactory implements TracerProviderFactoryInterface
{
    public function __construct(private DefaultResourceInfoFactory $resourceInfoFactory) {}

    public function create(iterable $processors = []): TracerProviderInterface
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

        return $builder->build();
    }
}
