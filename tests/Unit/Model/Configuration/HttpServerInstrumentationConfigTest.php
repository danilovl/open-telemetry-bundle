<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\{
    HttpServerInstrumentationConfig
};
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type HttpServerInstrumentationConfigArray from HttpServerInstrumentationConfig */
class HttpServerInstrumentationConfigTest extends TestCase
{
    /**
     * @phpstan-param HttpServerInstrumentationConfigArray $config
     */
    #[DataProvider('provideFromConfigCases')]
    public function testFromConfig(
        array $config,
        bool $enabled,
        bool $tracing,
        bool $metering,
        bool $defaultTraceIgnore
    ): void {
        $result = HttpServerInstrumentationConfig::fromConfig($config);

        $this->assertSame($enabled, $result->enabled);
        $this->assertSame($tracing, $result->tracingEnabled);
        $this->assertSame($metering, $result->meteringEnabled);
        $this->assertSame($defaultTraceIgnore, $result->defaultTraceIgnoreEnabled);
    }

    public static function provideFromConfigCases(): Generator
    {
        yield 'all enabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'default_trace_ignore_enabled' => true
            ],
            true, true, true, true
        ];
        yield 'all disabled' => [
            [
                'enabled' => false,
                'tracing' => ['enabled' => false],
                'metering' => ['enabled' => false],
                'default_trace_ignore_enabled' => false
            ],
            false, false, false, false
        ];
        yield 'trace ignore disabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'default_trace_ignore_enabled' => false
            ],
            true, true, true, false
        ];
    }
}
