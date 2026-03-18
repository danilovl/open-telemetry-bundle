<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\Exporter;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\{LogRecordExporterInterface as SdkLogRecordExporterInterface, ReadableLogRecord};
use OpenTelemetry\SDK\Common\Future\{CancellationInterface, FutureInterface};

/**
 * Decorator that filters log records by instrumentation scope before delegating
 * to the inner {@see LogRecordExporterInterface}.
 *
 * If the inner exporter returns an empty array from {@see LogRecordExporterInterface::getSupportedInstrumentation()},
 * all log records are passed through without filtering.
 */
final class InstrumentationFilteringLogRecordExporter implements SdkLogRecordExporterInterface
{
    public function __construct(private readonly LogRecordExporterInterface $inner) {}

    /**
     * @return FutureInterface<bool>
     */
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
                static fn (ReadableLogRecord $record): bool => in_array(
                    $record->getInstrumentationScope()->getName(),
                    $supported,
                    true
                )
            )
        );

        return $this->inner->export($filtered, $cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }
}
