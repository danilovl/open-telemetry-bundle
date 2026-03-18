<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log;

use OpenTelemetry\SDK\Logs\LogRecordExporterInterface as SdkLogRecordExporterInterface;

/**
 * Marker interface for custom log record exporters registered in the bundle.
 *
 * Implement this interface to have your exporter automatically discovered
 * and injected into LoggerProvider via DI autoconfiguration.
 * Each exporter is automatically wrapped in a SimpleLogRecordProcessor.
 *
 * {@see getSupportedInstrumentation()} controls which instrumentation scopes
 * (by name) this exporter will receive log records from.
 * Return an empty array to receive log records from all instrumentations.
 *
 * Example tag (auto-added via autoconfiguration):
 *   danilovl.open_telemetry.log_exporter
 */
interface LogRecordExporterInterface extends SdkLogRecordExporterInterface
{
    /**
     * Returns a list of instrumentation scope names this exporter handles.
     * An empty array means the exporter receives log records from all instrumentations.
     *
     * Instrumentation scope name corresponds to the name passed to CachedInstrumentation
     * (e.g. 'danilovl/open-telemetry').
     *
     * @return array<string>
     */
    public function getSupportedInstrumentation(): array;

    /**
     * Returns the priority of this exporter.
     * Higher value means the exporter's processor is added to the provider earlier.
     * Default: 0.
     */
    public function getPriority(): int;
}
