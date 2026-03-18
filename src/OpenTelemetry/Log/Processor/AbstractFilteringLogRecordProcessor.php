<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\Processor;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log\LogRecordProcessorInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;

/**
 * Base class for custom log record processors that need to filter by instrumentation scope.
 *
 * Override {@see doOnEmit()} to implement your processing logic.
 * Override {@see getSupportedInstrumentations()} to restrict processing to specific scopes.
 * Override {@see getPriority()} to control registration order (higher = earlier).
 *
 * Since {@see ReadWriteLogRecord} extends {@see \OpenTelemetry\SDK\Logs\ReadableLogRecord},
 * the instrumentation scope is accessible via {@see ReadWriteLogRecord::getInstrumentationScope()}.
 */
abstract class AbstractFilteringLogRecordProcessor implements LogRecordProcessorInterface
{
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
        $supported = $this->getSupportedInstrumentation();

        if ($supported !== [] && !in_array($record->getInstrumentationScope()->getName(), $supported, true)) {
            return;
        }

        $this->doOnEmit($record, $context);
    }

    /**
     * Called when a log record is emitted and passes the instrumentation scope filter.
     * Implement your custom processing logic here.
     */
    abstract protected function doOnEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void;

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
