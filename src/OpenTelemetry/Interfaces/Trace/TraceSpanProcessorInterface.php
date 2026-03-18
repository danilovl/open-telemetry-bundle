<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace;

use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * Marker interface for custom span processors registered in the bundle.
 *
 * Implement this interface to have your processor automatically discovered
 * and injected into TracerProvider via DI autoconfiguration.
 *
 * {@see getSupportedInstrumentation()} controls which instrumentation scopes
 * (by name) this processor will handle in {@see onEnd}.
 * Return an empty array to handle spans from all instrumentations.
 *
 * For convenience, extend {@see \Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\Processor\AbstractFilteringSpanProcessor}
 * which handles the filtering automatically.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.span_processor
 */
interface TraceSpanProcessorInterface extends SpanProcessorInterface
{
    /**
     * Returns a list of instrumentation scope names this processor handles.
     * An empty array means the processor receives spans from all instrumentations.
     *
     * Instrumentation scope name corresponds to the name passed to CachedInstrumentation
     * (e.g. 'danilovl/open-telemetry').
     *
     * @return array<string>
     */
    public function getSupportedInstrumentation(): array;

    /**
     * Returns the priority of this processor.
     * Higher value means the processor is added to the provider earlier.
     * Default: 0.
     */
    public function getPriority(): int;
}
