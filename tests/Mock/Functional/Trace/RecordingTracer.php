<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace;

use OpenTelemetry\API\Trace\{
    SpanBuilderInterface,
    TracerInterface
};
use Override;

class RecordingTracer implements TracerInterface
{
    /** @var list<RecordingSpan> */
    private array $spans = [];

    #[Override]
    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return new RecordingSpanBuilder($spanName, $this->spans);
    }

    #[Override]
    public function isEnabled(): bool
    {
        return true;
    }

    /** @return list<RecordingSpan> */
    public function getSpans(): array
    {
        return $this->spans;
    }

    public function reset(): void
    {
        $this->spans = [];
    }
}
