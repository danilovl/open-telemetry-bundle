<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\{
    NoopTracer,
    TracerInterface
};

/** With tracing disabled, NoopTracer still propagates the parent context. */
final readonly class ScopedInstrumentation
{
    public function __construct(
        private CachedInstrumentation $instrumentation,
        private bool $tracingEnabled = true,
    ) {}

    public static function create(string $name, bool $tracingEnabled = true): self
    {
        $instrumentation = new CachedInstrumentation($name);

        return new self($instrumentation, $tracingEnabled);
    }

    public function tracer(): TracerInterface
    {
        if (!$this->tracingEnabled) {
            return NoopTracer::getInstance();
        }

        return $this->instrumentation->tracer();
    }

    public function meter(): MeterInterface
    {
        return $this->instrumentation->meter();
    }

    public function isTracingEnabled(): bool
    {
        return $this->tracingEnabled;
    }
}
