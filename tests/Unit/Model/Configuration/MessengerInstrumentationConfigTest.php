<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\MessengerInstrumentationConfig;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type MessengerInstrumentationConfigArray from MessengerInstrumentationConfig */
class MessengerInstrumentationConfigTest extends TestCase
{
    /**
     * @phpstan-param MessengerInstrumentationConfigArray $config
     */
    #[DataProvider('provideFromConfigCases')]
    public function testFromConfig(
        array $config,
        bool $enabled,
        bool $tracing,
        bool $metering,
        bool $longRunning
    ): void {
        $result = MessengerInstrumentationConfig::fromConfig($config);

        $this->assertSame($enabled, $result->enabled);
        $this->assertSame($tracing, $result->tracingEnabled);
        $this->assertSame($metering, $result->meteringEnabled);
        $this->assertSame($longRunning, $result->longRunningCommandEnabled);
    }

    public static function provideFromConfigCases(): Generator
    {
        yield 'all enabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'long_running_command_enabled' => true,
            ],
            true, true, true, true,
        ];
        yield 'all disabled' => [
            [
                'enabled' => false,
                'tracing' => ['enabled' => false],
                'metering' => ['enabled' => false],
                'long_running_command_enabled' => false,
            ],
            false, false, false, false,
        ];
        yield 'long running disabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'long_running_command_enabled' => false,
            ],
            true, true, true, false,
        ];
    }
}
