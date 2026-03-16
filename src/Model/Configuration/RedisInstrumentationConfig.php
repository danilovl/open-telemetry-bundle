<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-import-type InstrumentationConfigArray from BaseInstrumentationConfig as BaseInstrumentationConfigArray
 * @phpstan-type RedisInstrumentationConfigArray array{
 *     enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
class RedisInstrumentationConfig extends BaseInstrumentationConfig
{
    public function __construct(
        bool $enabled,
        bool $tracingEnabled,
        bool $meteringEnabled
    ) {
        parent::__construct($enabled, $tracingEnabled, $meteringEnabled);
    }

    /**
     * @phpstan-param RedisInstrumentationConfigArray $config
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
