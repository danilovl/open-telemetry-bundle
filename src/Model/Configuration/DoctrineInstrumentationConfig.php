<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type DoctrineInstrumentationConfigArray array{
 *     enabled: bool,
 *     default_trace_ignore_enabled: bool,
 *     default_span_name_handler_enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
final class DoctrineInstrumentationConfig extends BaseInstrumentationConfig
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
     * @phpstan-import-type DoctrineInstrumentationConfigArray from DoctrineInstrumentationConfig
     * @param DoctrineInstrumentationConfigArray $config
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
