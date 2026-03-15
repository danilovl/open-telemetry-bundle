<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type EventsInstrumentationConfigArray array{
 *     enabled: bool,
 *     default_trace_ignore_enabled: bool,
 *     default_span_name_handler_enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
final class EventsInstrumentationConfig extends BaseInstrumentationConfig
{
    public function __construct(
        bool $enabled,
        bool $tracingEnabled,
        bool $meteringEnabled,
        public readonly bool $defaultTraceIgnoreEnabled,
        public readonly bool $defaultSpanNameHandlerEnabled
    ) {
        parent::__construct($enabled, $tracingEnabled, $meteringEnabled);
    }

    /**
     * @phpstan-import-type EventsInstrumentationConfigArray from EventsInstrumentationConfig
     * @param EventsInstrumentationConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: $config['enabled'],
            tracingEnabled: $config['tracing']['enabled'],
            meteringEnabled: $config['metering']['enabled'],
            defaultTraceIgnoreEnabled: $config['default_trace_ignore_enabled'],
            defaultSpanNameHandlerEnabled: $config['default_span_name_handler_enabled']
        );
    }
}
