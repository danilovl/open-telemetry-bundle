<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\EventsInstrumentationConfig;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type EventsInstrumentationConfigArray from EventsInstrumentationConfig */
class EventsInstrumentationConfigTest extends TestCase
{
    /**
     * @phpstan-param EventsInstrumentationConfigArray $config
     */
    #[DataProvider('provideFromConfigCases')]
    public function testFromConfig(
        array $config,
        bool $enabled,
        bool $tracing,
        bool $metering,
        bool $defaultTraceIgnore,
        bool $defaultSpanNameHandler
    ): void {
        $result = EventsInstrumentationConfig::fromConfig($config);

        $this->assertSame($enabled, $result->enabled);
        $this->assertSame($tracing, $result->tracingEnabled);
        $this->assertSame($metering, $result->meteringEnabled);
        $this->assertSame($defaultTraceIgnore, $result->defaultTraceIgnoreEnabled);
        $this->assertSame($defaultSpanNameHandler, $result->defaultSpanNameHandlerEnabled);
    }

    public static function provideFromConfigCases(): Generator
    {
        yield 'all enabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'default_trace_ignore_enabled' => true,
                'default_span_name_handler_enabled' => true
            ],
            true, true, true, true, true
        ];
        yield 'all disabled' => [
            [
                'enabled' => false,
                'tracing' => ['enabled' => false],
                'metering' => ['enabled' => false],
                'default_trace_ignore_enabled' => false,
                'default_span_name_handler_enabled' => false
            ],
            false, false, false, false, false
        ];
        yield 'span handler disabled' => [
            [
                'enabled' => true,
                'tracing' => ['enabled' => true],
                'metering' => ['enabled' => true],
                'default_trace_ignore_enabled' => true,
                'default_span_name_handler_enabled' => false
            ],
            true, true, true, true, false
        ];
    }
}
