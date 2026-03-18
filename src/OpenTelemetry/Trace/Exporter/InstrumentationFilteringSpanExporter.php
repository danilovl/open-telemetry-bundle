<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\Exporter;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace\TraceSpanExporterInterface;
use OpenTelemetry\SDK\Trace\{SpanDataInterface, SpanExporterInterface};
use OpenTelemetry\SDK\Common\Future\{CancellationInterface, FutureInterface};

/**
 * Decorator that filters spans by instrumentation scope before delegating
 * to the inner {@see TraceSpanExporterInterface}.
 *
 * If the inner exporter returns an empty array from {@see TraceSpanExporterInterface::getSupportedInstrumentation()},
 * all spans are passed through without filtering.
 */
final class InstrumentationFilteringSpanExporter implements SpanExporterInterface
{
    public function __construct(private readonly TraceSpanExporterInterface $inner) {}

    /** @return FutureInterface<bool> */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $supported = $this->inner->getSupportedInstrumentation();

        if ($supported === []) {
            return $this->inner->export($batch, $cancellation);
        }

        $items = is_array($batch) ? $batch : iterator_to_array($batch);

        $filtered = array_values(
            array_filter(
                $items,
                static fn (SpanDataInterface $span): bool => in_array(
                    $span->getInstrumentationScope()->getName(),
                    $supported,
                    true
                )
            )
        );

        return $this->inner->export($filtered, $cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
