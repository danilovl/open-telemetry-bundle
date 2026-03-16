<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\{
    Configuration,
    OpenTelemetryExtension
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler\DefaultDoctrineSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore\DefaultDoctrineTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\TracingRedis;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\ConsoleTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler\DefaultEventSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore\DefaultEventTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore\DefaultHttpRequestTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\LongRunningCommand\DefaultLongRunningCommand;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Generator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OpenTelemetryExtensionTest extends TestCase
{
    private OpenTelemetryExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new OpenTelemetryExtension;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $this->extension->load([$config], $container);

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    private function allDisabledConfig(): array
    {
        $off = [
            'enabled' => false,
            'tracing' => ['enabled' => false],
            'metering' => ['enabled' => false]
        ];

        return [
            'instrumentation' => [
                'http_server' => $off + ['default_trace_ignore_enabled' => false],
                'messenger' => $off + ['long_running_command_enabled' => false],
                'console' => $off,
                'traceable' => $off,
                'twig' => $off,
                'cache' => $off,
                'doctrine' => $off + ['default_trace_ignore_enabled' => false, 'default_span_name_handler_enabled' => false],
                'redis' => $off,
                'predis' => $off,
                'mailer' => $off,
                'events' => $off + ['default_trace_ignore_enabled' => false, 'default_span_name_handler_enabled' => false],
                'async' => $off,
                'http_client' => $off
            ],
        ];
    }

    public function testAlias(): void
    {
        $this->assertSame(Configuration::ALIAS, $this->extension->getAlias());
    }

    public function testLoadRegistersCachedInstrumentationDefinition(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition('danilovl.open_telemetry.cached_instrumentation'));
    }

    public function testLoadRegistersCachedInstrumentationAlias(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasAlias(CachedInstrumentation::class));
    }

    public function testLoadRegistersResourceInfoDefinition(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition('danilovl.open_telemetry.resource_info'));
    }

    #[DataProvider('provideExpectedCoreDefinitionsExistCases')]
    public function testExpectedCoreDefinitionsExist(string $id): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition($id));
    }

    public function testHttpRequestTraceIgnoreRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'http_server' => ['default_trace_ignore_enabled' => true],
            ],
        ]);

        $this->assertTrue($container->hasDefinition(DefaultHttpRequestTraceIgnore::class));
    }

    public function testHttpRequestTraceIgnoreRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'http_server' => ['default_trace_ignore_enabled' => false],
            ],
        ]);

        $this->assertFalse($container->hasDefinition(DefaultHttpRequestTraceIgnore::class));
    }

    public function testHttpRequestSubscriberRemovedWhenTracingDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'http_server' => [
                    'enabled' => false,
                    'tracing' => ['enabled' => false],
                    'metering' => ['enabled' => false],
                    'default_trace_ignore_enabled' => false
                ],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(HttpRequestTracingSubscriber::class));
    }

    public function testDoctrineSpanNameHandlerRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'doctrine' => ['default_span_name_handler_enabled' => true],
            ]
        ]);

        $this->assertTrue($container->hasDefinition(DefaultDoctrineSpanNameHandler::class));
    }

    public function testDoctrineSpanNameHandlerRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'doctrine' => ['default_span_name_handler_enabled' => false],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(DefaultDoctrineSpanNameHandler::class));
    }

    public function testDoctrineTraceIgnoreRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'doctrine' => ['default_trace_ignore_enabled' => true],
            ]
        ]);

        $this->assertTrue($container->hasDefinition(DefaultDoctrineTraceIgnore::class));
    }

    public function testDoctrineTraceIgnoreRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'doctrine' => ['default_trace_ignore_enabled' => false],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(DefaultDoctrineTraceIgnore::class));
    }

    public function testEventsSpanNameHandlerRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'events' => ['default_span_name_handler_enabled' => true],
            ]
        ]);

        $this->assertTrue($container->hasDefinition(DefaultEventSpanNameHandler::class));
    }

    public function testEventsSpanNameHandlerRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'events' => ['default_span_name_handler_enabled' => false],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(DefaultEventSpanNameHandler::class));
    }

    public function testEventsTraceIgnoreRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'events' => ['default_trace_ignore_enabled' => true],
            ]
        ]);

        $this->assertTrue($container->hasDefinition(DefaultEventTraceIgnore::class));
    }

    public function testEventsTraceIgnoreRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'events' => ['default_trace_ignore_enabled' => false],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(DefaultEventTraceIgnore::class));
    }

    public function testConsoleSubscriberRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'console' => [
                    'enabled' => false,
                    'tracing' => ['enabled' => false],
                    'metering' => ['enabled' => false]
                ],
            ]
        ]);

        $this->assertFalse($container->hasDefinition(ConsoleTracingSubscriber::class));
    }

    public function testLongRunningCommandRegisteredByDefault(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'messenger' => ['long_running_command_enabled' => true],
            ]
        ]);

        $defaultServiceId = InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND . '.default';

        $this->assertTrue($container->hasDefinition($defaultServiceId));
    }

    public function testLongRunningCommandNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'messenger' => [
                    'long_running_command_enabled' => false,
                    'enabled' => false,
                    'tracing' => ['enabled' => false],
                    'metering' => ['enabled' => false]
                ],
            ]
        ]);

        $defaultServiceId = InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND . '.default';

        $this->assertFalse($container->hasDefinition($defaultServiceId));
    }

    public function testDefaultLongRunningCommandClass(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'messenger' => ['long_running_command_enabled' => true],
            ]
        ]);

        $defaultServiceId = InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND . '.default';
        $definition = $container->getDefinition($defaultServiceId);

        $this->assertSame(DefaultLongRunningCommand::class, $definition->getClass());
    }

    public function testRedisInstrumentationRegisteredWhenEnabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'predis' => [
                    'enabled' => true
                ]
            ]
        ]);

        $this->assertTrue($container->hasDefinition(TracingRedis::class));
    }

    public function testRedisInstrumentationRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer([
            'instrumentation' => [
                'predis' => [
                    'enabled' => false,
                    'tracing' => ['enabled' => false],
                    'metering' => ['enabled' => false]
                ]
            ]
        ]);

        $this->assertFalse($container->hasDefinition(TracingRedis::class));
    }

    public static function provideExpectedCoreDefinitionsExistCases(): Generator
    {
        yield 'cached instrumentation' => ['danilovl.open_telemetry.cached_instrumentation'];
        yield 'resource info' => ['danilovl.open_telemetry.resource_info'];
    }
}
