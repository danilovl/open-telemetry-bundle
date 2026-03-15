<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type MessengerInstrumentationConfigArray array{
 *     enabled: bool,
 *     long_running_command_enabled: bool,
 *     tracing: array{enabled: bool},
 *     metering: array{enabled: bool}
 * }
 */
final class MessengerInstrumentationConfig extends BaseInstrumentationConfig
{
    public function __construct(
        bool $enabled,
        bool $tracingEnabled,
        bool $meteringEnabled,
        public readonly bool $longRunningCommandEnabled
    ) {
        parent::__construct($enabled, $tracingEnabled, $meteringEnabled);
    }

    /**
     * @phpstan-import-type MessengerInstrumentationConfigArray from MessengerInstrumentationConfig
     * @param MessengerInstrumentationConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: $config['enabled'],
            tracingEnabled: $config['tracing']['enabled'],
            meteringEnabled: $config['metering']['enabled'],
            longRunningCommandEnabled: $config['long_running_command_enabled']
        );
    }
}
