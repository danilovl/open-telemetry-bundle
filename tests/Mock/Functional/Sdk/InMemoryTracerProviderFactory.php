<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\{
    TracerProvider,
    TracerProviderInterface
};

final class InMemoryTracerProviderFactory implements TracerProviderFactoryInterface
{
    public readonly InMemoryExporter $exporter;

    public function __construct(private readonly ?DefaultResourceInfoFactory $resourceInfoFactory = null)
    {
        $this->exporter = new InMemoryExporter;
    }

    public function create(iterable $processors = [], iterable $exporters = []): TracerProviderInterface
    {
        $spanProcessor = new SimpleSpanProcessor($this->exporter);
        $builder = TracerProvider::builder()->addSpanProcessor($spanProcessor);

        if ($this->resourceInfoFactory !== null) {
            $builder->setResource($this->resourceInfoFactory->createResource());
        }

        foreach ($processors as $processor) {
            $builder->addSpanProcessor($processor);
        }

        return $builder->build();
    }
}
