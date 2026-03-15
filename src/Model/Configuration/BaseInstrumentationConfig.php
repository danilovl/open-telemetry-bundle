<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type InstrumentationConfigArray array{
 *     enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
class BaseInstrumentationConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $tracingEnabled,
        public readonly bool $meteringEnabled
    ) {}

    /**
     * @phpstan-import-type InstrumentationConfigArray from BaseInstrumentationConfig
     * @param InstrumentationConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: $config['enabled'],
            tracingEnabled: $config['tracing']['enabled'],
            meteringEnabled: $config['metering']['enabled']
        );
    }
}
