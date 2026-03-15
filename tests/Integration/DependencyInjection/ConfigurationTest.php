<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\Configuration;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    private Processor $processor;

    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor;
        $this->configuration = new Configuration;
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array{
     *   service: array{namespace: string|null, name: string|null, version: string|null, environment: string|null},
     *   instrumentation: array<string, array<string, mixed>>
     * }
     */
    private function process(array $configs = []): array
    {
        /** @var array{service: array{namespace: string|null, name: string|null, version: string|null, environment: string|null}, instrumentation: array<string, array<string, mixed>>} $result */
        $result = $this->processor->processConfiguration($this->configuration, $configs);

        return $result;
    }

    public function testAlias(): void
    {
        $this->assertSame('danilovl_open_telemetry', Configuration::ALIAS);
    }

    public function testDefaultServiceConfig(): void
    {
        $config = $this->process();

        $this->assertNull($config['service']['namespace']);
        $this->assertNull($config['service']['name']);
        $this->assertNull($config['service']['version']);
        $this->assertNull($config['service']['environment']);
    }

    /**
     * @param array<string, string|null> $serviceConfig
     */
    #[DataProvider('provideServiceConfigCases')]
    public function testServiceConfig(
        array $serviceConfig,
        ?string $namespace,
        ?string $name,
        ?string $version,
        ?string $environment
    ): void {
        $config = $this->process([['service' => $serviceConfig]]);

        $this->assertSame($namespace, $config['service']['namespace']);
        $this->assertSame($name, $config['service']['name']);
        $this->assertSame($version, $config['service']['version']);
        $this->assertSame($environment, $config['service']['environment']);
    }

    #[DataProvider('provideInstrumentationEnabledByDefaultCases')]
    public function testInstrumentationEnabledByDefault(string $key): void
    {
        $config = $this->process();
        /** @var array<string, mixed> $item */
        $item = $config['instrumentation'][$key];
        /** @var array<string, mixed> $tracing */
        $tracing = $item['tracing'];

        $this->assertTrue($item['enabled']);
        $this->assertTrue($tracing['enabled']);
    }

    #[DataProvider('provideDisableInstrumentationCases')]
    public function testDisableInstrumentation(string $key): void
    {
        $config = $this->process([[
            'instrumentation' => [$key => ['enabled' => false]]
        ]]);

        $this->assertFalse($config['instrumentation'][$key]['enabled']);
    }

    #[DataProvider('provideDisableTracingCases')]
    public function testDisableTracing(string $key): void
    {
        $config = $this->process([[
            'instrumentation' => [$key => ['tracing' => ['enabled' => false]]],
        ]]);
        /** @var array<string, mixed> $tracing */
        $tracing = $config['instrumentation'][$key]['tracing'];

        $this->assertFalse($tracing['enabled']);
    }

    #[DataProvider('provideDefaultTraceIgnoreNodes')]
    public function testDefaultTraceIgnoreEnabledByDefault(string $key): void
    {
        $config = $this->process();

        $this->assertTrue($config['instrumentation'][$key]['default_trace_ignore_enabled']);
    }

    #[DataProvider('provideDefaultTraceIgnoreNodes')]
    public function testDisableDefaultTraceIgnore(string $key): void
    {
        $config = $this->process([[
            'instrumentation' => [$key => ['default_trace_ignore_enabled' => false]],
        ]]);

        $this->assertFalse($config['instrumentation'][$key]['default_trace_ignore_enabled']);
    }

    #[DataProvider('provideDefaultSpanNameHandlerNodes')]
    public function testDefaultSpanNameHandlerEnabledByDefault(string $key): void
    {
        $config = $this->process();

        $this->assertTrue($config['instrumentation'][$key]['default_span_name_handler_enabled']);
    }

    #[DataProvider('provideDefaultSpanNameHandlerNodes')]
    public function testDisableDefaultSpanNameHandler(string $key): void
    {
        $config = $this->process([[
            'instrumentation' => [$key => ['default_span_name_handler_enabled' => false]],
        ]]);

        $this->assertFalse($config['instrumentation'][$key]['default_span_name_handler_enabled']);
    }

    public function testMessengerLongRunningCommandEnabledByDefault(): void
    {
        $config = $this->process();

        $this->assertTrue($config['instrumentation']['messenger']['long_running_command_enabled']);
    }

    public function testMessengerLongRunningCommandDisable(): void
    {
        $config = $this->process([[
            'instrumentation' => ['messenger' => ['long_running_command_enabled' => false]],
        ]]);

        $this->assertFalse($config['instrumentation']['messenger']['long_running_command_enabled']);
    }

    public function testCacheMeteringDefaultTrue(): void
    {
        $config = $this->process();
        /** @var array<string, mixed> $metering */
        $metering = $config['instrumentation']['cache']['metering'];

        $this->assertTrue($metering['enabled']);
    }

    public function testMeteringCanBeEnabled(): void
    {
        $config = $this->process([[
            'instrumentation' => ['cache' => ['metering' => ['enabled' => true]]],
        ]]);
        /** @var array<string, mixed> $metering */
        $metering = $config['instrumentation']['cache']['metering'];

        $this->assertTrue($metering['enabled']);
    }

    public function testMultipleConfigsMerged(): void
    {
        $config = $this->process([
            ['service' => ['name' => 'first']],
            ['service' => ['name' => 'second']],
        ]);

        $this->assertSame('second', $config['service']['name']);
    }

    public function testAllInstrumentationKeysPresent(): void
    {
        $config = $this->process();

        $instrumentation = ['http_server', 'messenger', 'console', 'traceable', 'twig', 'cache', 'doctrine', 'redis', 'mailer', 'events', 'async', 'http_client'];

        foreach ($instrumentation as $key) {
            $this->assertArrayHasKey($key, $config['instrumentation'], "Key '$key' missing");
        }
    }

    public function testGetConfigTreeBuilderReturnsTreeBuilder(): void
    {
        $treeBuilder = $this->configuration->getConfigTreeBuilder();

        $this->assertInstanceOf(TreeBuilder::class, $treeBuilder);
    }

    public static function provideInstrumentationEnabledByDefaultCases(): Generator
    {
        $instrumentation = ['http_server', 'messenger', 'console', 'traceable', 'twig', 'cache', 'doctrine', 'redis', 'mailer', 'events', 'async', 'http_client'];

        foreach ($instrumentation as $key) {
            yield $key => [$key];
        }
    }

    public static function provideDisableInstrumentationCases(): Generator
    {
        yield 'http_server' => ['http_server'];
        yield 'console' => ['console'];
        yield 'doctrine' => ['doctrine'];
        yield 'events' => ['events'];
        yield 'messenger' => ['messenger'];
    }

    public static function provideDisableTracingCases(): Generator
    {
        yield 'http_server' => ['http_server'];
        yield 'messenger' => ['messenger'];
        yield 'console' => ['console'];
        yield 'redis' => ['redis'];
        yield 'mailer' => ['mailer'];
    }

    public static function provideServiceConfigCases(): Generator
    {
        yield 'full' => [
            ['namespace' => 'ns', 'name' => 'app', 'version' => '1.0', 'environment' => 'prod'],
            'ns', 'app', '1.0', 'prod'
        ];
        yield 'partial' => [
            ['name' => 'my-app', 'environment' => 'dev'],
            null, 'my-app', null, 'dev'
        ];
        yield 'all null' => [
            ['namespace' => null, 'name' => null, 'version' => null, 'environment' => null],
            null, null, null, null
        ];
    }

    public static function provideDefaultTraceIgnoreNodes(): Generator
    {
        yield 'http_server' => ['http_server'];
        yield 'doctrine' => ['doctrine'];
        yield 'events' => ['events'];
    }

    public static function provideDefaultSpanNameHandlerNodes(): Generator
    {
        yield 'doctrine' => ['doctrine'];
        yield 'events' => ['events'];
    }
}
