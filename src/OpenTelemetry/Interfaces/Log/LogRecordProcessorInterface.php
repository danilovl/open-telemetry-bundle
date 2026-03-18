<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log;

use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface as SdkLogRecordProcessorInterface;

/**
 * Marker interface for custom log record processors registered in the bundle.
 *
 * Implement this interface to have your processor automatically discovered
 * and injected into LoggerProvider via DI autoconfiguration.
 *
 * {@see getSupportedInstrumentation()} controls which instrumentation scopes
 * (by name) this processor will handle in {@see onEmit}.
 * Return an empty array to handle log records from all instrumentations.
 *
 * For convenience, extend {@see \Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\Processor\AbstractFilteringLogRecordProcessor}
 * which handles the filtering automatically.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.log_processor
 */
interface LogRecordProcessorInterface extends SdkLogRecordProcessorInterface
{
    /**
     * Returns a list of instrumentation scope names this processor handles.
     * An empty array means the processor receives log records from all instrumentations.
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
