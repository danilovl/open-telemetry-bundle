<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider;

use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace\{
    RecordingSpan,
    RecordingTracer};
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Override;

class RecordingTracerProvider implements TracerProviderInterface
{
    /** @var array<string, RecordingTracer> */
    private array $tracers = [];

    /**
     * @param iterable<mixed> $attributes
     */
    #[Override]
    public function getTracer(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = []
    ): TracerInterface {
        if (!isset($this->tracers[$name])) {
            $this->tracers[$name] = new RecordingTracer;
        }

        return $this->tracers[$name];
    }

    /** @return list<RecordingSpan> */
    public function getSpans(): array
    {
        $spans = [];
        foreach ($this->tracers as $tracer) {
            foreach ($tracer->getSpans() as $span) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    public function reset(): void
    {
        foreach ($this->tracers as $tracer) {
            $tracer->reset();
        }
    }

    #[Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    /** @param Configurator<mixed> $configurator */
    #[Override]
    public function updateConfigurator(Configurator $configurator): void {}
}
