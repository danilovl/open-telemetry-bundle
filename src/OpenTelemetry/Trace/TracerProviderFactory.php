<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace;

use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\{
    SamplerInterface,
    SpanProcessorInterface,
    TracerProvider,
    TracerProviderInterface
};
use OpenTelemetry\SDK\Trace\Sampler\{
    AlwaysOnSampler,
    ParentBased
};

final class TracerProviderFactory
{
    /**
     * @param SpanProcessorInterface[] $processors
     */
    public static function create(
        ResourceInfo $resource,
        array $processors = [],
        ?SamplerInterface $sampler = null,
    ): TracerProviderInterface {
        $sampler ??= new ParentBased(new AlwaysOnSampler);

        $builder = TracerProvider::builder()
            ->setResource($resource)
            ->setSampler($sampler);

        foreach ($processors as $processor) {
            $builder->addSpanProcessor($processor);
        }

        return $builder->build();
    }
}
