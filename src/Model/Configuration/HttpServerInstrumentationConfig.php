<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type HttpServerInstrumentationConfigArray array{
 *     enabled: bool,
 *     default_trace_ignore_enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
final class HttpServerInstrumentationConfig extends BaseInstrumentationConfig
{
    public function __construct(
        bool $enabled,
        bool $tracingEnabled,
        bool $meteringEnabled,
        public readonly bool $defaultTraceIgnoreEnabled
    ) {
        parent::__construct($enabled, $tracingEnabled, $meteringEnabled);
    }

    /**
     * @phpstan-import-type HttpServerInstrumentationConfigArray from HttpServerInstrumentationConfig
     * @param HttpServerInstrumentationConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: $config['enabled'],
            tracingEnabled: $config['tracing']['enabled'],
            meteringEnabled: $config['metering']['enabled'],
            defaultTraceIgnoreEnabled: $config['default_trace_ignore_enabled']
        );
    }
}
