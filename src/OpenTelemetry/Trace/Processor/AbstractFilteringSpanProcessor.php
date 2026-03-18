<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\Processor;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace\TraceSpanProcessorInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\{
    ReadableSpanInterface,
    ReadWriteSpanInterface
};

/**
 * Base class for custom span processors that need to filter by instrumentation scope.
 *
 * Override {@see doOnEnd()} to implement your processing logic.
 * Override {@see getSupportedInstrumentations()} to restrict processing to specific scopes.
 * Override {@see getPriority()} to control registration order (higher = earlier).
 *
 * {@see onStart()} is available for override if you need to act when a span starts,
 * but instrumentation scope filtering is not applied there because the span
 * has not ended yet and scope context is not yet meaningful for filtering.
 */
abstract class AbstractFilteringSpanProcessor implements TraceSpanProcessorInterface
{
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

    public function onEnd(ReadableSpanInterface $span): void
    {
        $supported = $this->getSupportedInstrumentation();

        if ($supported !== [] && !in_array($span->getInstrumentationScope()->getName(), $supported, true)) {
            return;
        }

        $this->doOnEnd($span);
    }

    /**
     * Called when a span ends and passes the instrumentation scope filter.
     * Implement your custom processing logic here.
     */
    abstract protected function doOnEnd(ReadableSpanInterface $span): void;

    /**
     * {@inheritdoc}
     * Override to restrict this processor to specific instrumentation scope names.
     *
     * @return array<string>
     */
    public function getSupportedInstrumentation(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     * Override to control processor registration order.
     */
    public function getPriority(): int
    {
        return 0;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
