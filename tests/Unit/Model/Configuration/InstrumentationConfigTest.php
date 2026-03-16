<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\{
    BaseInstrumentationConfig,
    DoctrineInstrumentationConfig,
    EventsInstrumentationConfig,
    HttpServerInstrumentationConfig,
    InstrumentationConfig,
    MessengerInstrumentationConfig,
    PRedisInstrumentationConfig,
};
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type InstrumentationConfigArray from InstrumentationConfig */
class InstrumentationConfigTest extends TestCase
{
    /**
     * @phpstan-param class-string<BaseInstrumentationConfig> $expectedClass
     */
    #[DataProvider('provideGetByKeyCases')]
    public function testGetByKey(string $key, string $expectedClass): void
    {
        $config = InstrumentationConfig::fromConfig(self::buildFullConfig());

        $this->assertInstanceOf($expectedClass, $config->getByKey($key));
    }

    public function testGetByKeyThrowsOnUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown/i');

        $config = InstrumentationConfig::fromConfig(self::buildFullConfig());
        $config->getByKey('nonexistent_key');
    }

    public function testFromConfigCreatesCorrectTypes(): void
    {
        $result = InstrumentationConfig::fromConfig(self::buildFullConfig());

        $this->assertInstanceOf(HttpServerInstrumentationConfig::class, $result->httpServer);
        $this->assertInstanceOf(MessengerInstrumentationConfig::class, $result->messenger);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->console);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->twig);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->cache);
        $this->assertInstanceOf(DoctrineInstrumentationConfig::class, $result->doctrine);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->redis);
        $this->assertInstanceOf(PRedisInstrumentationConfig::class, $result->predis);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->mailer);
        $this->assertInstanceOf(EventsInstrumentationConfig::class, $result->events);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->async);
        $this->assertInstanceOf(BaseInstrumentationConfig::class, $result->httpClient);
    }

    public function testFromConfigAllEnabled(): void
    {
        $result = InstrumentationConfig::fromConfig(self::buildFullConfig(true));

        $this->assertTrue($result->httpServer->enabled);
        $this->assertTrue($result->doctrine->defaultTraceIgnoreEnabled);
        $this->assertTrue($result->messenger->longRunningCommandEnabled);
        $this->assertTrue($result->events->defaultSpanNameHandlerEnabled);
    }

    public function testFromConfigAllDisabled(): void
    {
        $result = InstrumentationConfig::fromConfig(self::buildFullConfig(false));

        $this->assertFalse($result->httpServer->enabled);
        $this->assertFalse($result->doctrine->defaultTraceIgnoreEnabled);
        $this->assertFalse($result->messenger->longRunningCommandEnabled);
    }

    /** @return array{enabled: bool, tracing: array{enabled: bool}, metering: array{enabled: bool}} */
    private static function baseSection(bool $enabled = true): array
    {
        return ['enabled' => $enabled, 'tracing' => ['enabled' => $enabled], 'metering' => ['enabled' => $enabled]];
    }

    /** @phpstan-return InstrumentationConfigArray */
    private static function buildFullConfig(bool $enabled = true): array
    {
        return [
            'http_server' => self::baseSection($enabled) + ['default_trace_ignore_enabled' => $enabled],
            'messenger' => self::baseSection($enabled) + ['long_running_command_enabled' => $enabled],
            'console' => self::baseSection($enabled),
            'traceable' => self::baseSection($enabled),
            'twig' => self::baseSection($enabled),
            'cache' => self::baseSection($enabled),
            'doctrine' => self::baseSection($enabled) + ['default_trace_ignore_enabled' => $enabled, 'default_span_name_handler_enabled' => $enabled],
            'redis' => self::baseSection($enabled),
            'predis' => self::baseSection($enabled),
            'mailer' => self::baseSection($enabled),
            'events' => self::baseSection($enabled) + ['default_trace_ignore_enabled' => $enabled, 'default_span_name_handler_enabled' => $enabled],
            'async' => self::baseSection($enabled),
            'http_client' => self::baseSection($enabled),
        ];
    }

    public static function provideGetByKeyCases(): Generator
    {
        yield 'http_server' => ['http_server', HttpServerInstrumentationConfig::class];
        yield 'messenger' => ['messenger', MessengerInstrumentationConfig::class];
        yield 'console' => ['console', BaseInstrumentationConfig::class];
        yield 'traceable' => ['traceable', BaseInstrumentationConfig::class];
        yield 'twig' => ['twig', BaseInstrumentationConfig::class];
        yield 'cache' => ['cache', BaseInstrumentationConfig::class];
        yield 'doctrine' => ['doctrine', DoctrineInstrumentationConfig::class];
        yield 'redis' => ['redis', BaseInstrumentationConfig::class];
        yield 'predis' => ['predis', PRedisInstrumentationConfig::class];
        yield 'mailer' => ['mailer', BaseInstrumentationConfig::class];
        yield 'events' => ['events', EventsInstrumentationConfig::class];
        yield 'async' => ['async', BaseInstrumentationConfig::class];
        yield 'http_client' => ['http_client', BaseInstrumentationConfig::class];
    }
}
