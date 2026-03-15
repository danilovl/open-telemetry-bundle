<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\BaseInstrumentationConfig;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type InstrumentationConfigArray from BaseInstrumentationConfig */
class BaseInstrumentationConfigTest extends TestCase
{
    /**
     * @phpstan-param InstrumentationConfigArray $config
     */
    #[DataProvider('provideFromConfigCases')]
    public function testFromConfig(array $config, bool $enabled, bool $tracing, bool $metering): void
    {
        $result = BaseInstrumentationConfig::fromConfig($config);

        $this->assertSame($enabled, $result->enabled);
        $this->assertSame($tracing, $result->tracingEnabled);
        $this->assertSame($metering, $result->meteringEnabled);
    }

    public static function provideFromConfigCases(): Generator
    {
        yield 'all enabled' => [
            ['enabled' => true, 'tracing' => ['enabled' => true], 'metering' => ['enabled' => true]],
            true, true, true
        ];
        yield 'all disabled' => [
            ['enabled' => false, 'tracing' => ['enabled' => false], 'metering' => ['enabled' => false]],
            false, false, false
        ];
        yield 'only tracing enabled' => [
            ['enabled' => true, 'tracing' => ['enabled' => true], 'metering' => ['enabled' => false]],
            true, true, false
        ];
        yield 'only metering enabled' => [
            ['enabled' => true, 'tracing' => ['enabled' => false], 'metering' => ['enabled' => true]],
            true, false, true
        ];
    }
}
