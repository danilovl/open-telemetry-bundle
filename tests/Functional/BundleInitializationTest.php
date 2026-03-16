<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional;

use Danilovl\OpenTelemetryBundle\DependencyInjection\{
    Configuration,
    OpenTelemetryExtension
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Async\AsyncTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\TracingDbalMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler\DefaultDoctrineSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore\DefaultDoctrineTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    TracingPhpRedis
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\ConsoleTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler\DefaultEventSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore\DefaultEventTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TracingEventDispatcher;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore\DefaultHttpRequestTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\MailerTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\{
    MessageBusTracingMiddleware,
    MessengerFlushSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\{
    TraceableHookSubscriber,
    TraceableSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\TraceableTwigExtension;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\{
    TracingSpanServiceInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\DefaultLoggerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\{
    MockMeterProviderFactory,
    MockTracerProviderFactory,
    MockLoggerProviderFactory
};
use Generator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Exception;

class BundleInitializationTest extends TestCase
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
     * @param array<string, mixed> $config
     */
    private function buildContainerWithMockFactories(array $config = []): ContainerBuilder
    {
        $container = $this->buildContainer($config);

        $container->getDefinition(DefaultTracerProviderFactory::class)
            ->setClass(MockTracerProviderFactory::class)
            ->setArguments([]);

        $container->getDefinition(DefaultMeterProviderFactory::class)
            ->setClass(MockMeterProviderFactory::class)
            ->setArguments([]);

        $container->getDefinition(DefaultLoggerProviderFactory::class)
            ->setClass(MockLoggerProviderFactory::class)
            ->setArguments([]);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $expectedServices
     * @param array<int, string> $unexpectedServices
     */
    #[DataProvider('provideInstrumentationRegistrationCases')]
    public function testInstrumentationRegistration(
        array $config,
        array $expectedServices,
        array $unexpectedServices = []
    ): void {
        $container = $this->buildContainer($config);

        foreach ($expectedServices as $service) {
            $this->assertTrue($container->hasDefinition($service), sprintf('Service "%s" should be registered.', $service));
        }

        foreach ($unexpectedServices as $service) {
            $this->assertFalse($container->hasDefinition($service), sprintf('Service "%s" should not be registered.', $service));
        }
    }

    public function testAliasIsCorrect(): void
    {
        $this->assertSame(Configuration::ALIAS, $this->extension->getAlias());
        $this->assertSame('danilovl_open_telemetry', $this->extension->getAlias());
    }

    public function testCachedInstrumentationDefinitionRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition('danilovl.open_telemetry.cached_instrumentation'));
    }

    public function testCachedInstrumentationAliasRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasAlias(CachedInstrumentation::class));
    }

    public function testResourceInfoDefinitionRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition('danilovl.open_telemetry.resource_info'));
    }

    public function testTracerProviderFactoryRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition(DefaultTracerProviderFactory::class));
    }

    public function testMeterProviderFactoryRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition(DefaultMeterProviderFactory::class));
    }

    public function testLoggerProviderFactoryRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition(DefaultLoggerProviderFactory::class));
    }

    public function testTracingSpanServiceRegistered(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());

        $this->assertTrue($container->hasDefinition(TracingSpanServiceInterface::class));
    }

    public function testTracingSpanServiceMethodsCanBeCalled(): void
    {
        $container = $this->buildContainer($this->allDisabledConfig());
        $container->compile();

        /** @var TracingSpanServiceInterface $service */
        $service = $container->get(TracingSpanServiceInterface::class);

        $this->assertInstanceOf(TracingSpanServiceInterface::class, $service);

        $service->start('parent')
            ->setAttribute('parent-attr', 'value');

        $service->start('child')
            ->setAttribute('child-attr', 'value')
            ->addEvent('event')
            ->addErrorEvent('error')
            ->recordHandledException(new Exception('test'))
            ->markOutcomeAsFailure();

        $service->end();

        $service->markOutcomeAsSuccess()->end();
    }

    public function testMockFactoriesCanReplaceDefaults(): void
    {
        $container = $this->buildContainerWithMockFactories($this->allDisabledConfig());

        $tracerDef = $container->getDefinition(DefaultTracerProviderFactory::class);
        $meterDef = $container->getDefinition(DefaultMeterProviderFactory::class);
        $loggerDef = $container->getDefinition(DefaultLoggerProviderFactory::class);

        $this->assertSame(MockTracerProviderFactory::class, $tracerDef->getClass());
        $this->assertSame(MockMeterProviderFactory::class, $meterDef->getClass());
        $this->assertSame(MockLoggerProviderFactory::class, $loggerDef->getClass());
    }

    /**
     * @param array<string, mixed> $serviceConfig
     */
    #[DataProvider('provideServiceConfigIsAcceptedCases')]
    public function testServiceConfigIsAccepted(array $serviceConfig): void
    {
        $config = array_merge(['service' => $serviceConfig], $this->allDisabledConfig());
        $container = $this->buildContainer($config);

        $this->assertTrue($container->hasDefinition('danilovl.open_telemetry.resource_info'));
    }

    /**
     * @return array<string, mixed>
     */
    private function allDisabledConfig(): array
    {
        $off = [
            'enabled' => false,
            'tracing' => [
                'enabled' => false
            ],
            'metering' => [
                'enabled' => false
            ]
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
                'mailer' => $off,
                'events' => $off + ['default_trace_ignore_enabled' => false, 'default_span_name_handler_enabled' => false],
                'async' => $off,
                'http_client' => $off,
            ],
        ];
    }

    public static function provideInstrumentationRegistrationCases(): Generator
    {
        $on = [
            'enabled' => true,
            'tracing' => [
                'enabled' => true
            ],
            'metering' => [
                'enabled' => false
            ]
        ];

        $off = [
            'enabled' => false,
            'tracing' => [
                'enabled' => false
            ],
            'metering' => [
                'enabled' => false
            ]
        ];

        yield 'http_server enabled' => [
            'config' => [
                'instrumentation' => [
                    'http_server' => $on + ['default_trace_ignore_enabled' => true]
                ]
            ],
            'expectedServices' => [
                HttpRequestTracingSubscriber::class,
                DefaultHttpRequestTraceIgnore::class
            ],
        ];

        yield 'http_server disabled' => [
            'config' => [
                'instrumentation' => [
                    'http_server' => $off
                ]
            ],
            'expectedServices' => [],
            'unexpectedServices' => [
                HttpRequestTracingSubscriber::class
            ]
        ];

        yield 'doctrine enabled' => [
            'config' => [
                'instrumentation' => [
                    'doctrine' => $on + ['default_trace_ignore_enabled' => true, 'default_span_name_handler_enabled' => true]
                ]
            ],
            'expectedServices' => [
                TracingDbalMiddleware::class,
                DefaultDoctrineTraceIgnore::class,
                DefaultDoctrineSpanNameHandler::class
            ]
        ];

        yield 'events enabled' => [
            'config' => [
                'instrumentation' => [
                    'events' => $on + ['default_trace_ignore_enabled' => true, 'default_span_name_handler_enabled' => true]
                ]
            ],
            'expectedServices' => [
                TracingEventDispatcher::class,
                DefaultEventTraceIgnore::class,
                DefaultEventSpanNameHandler::class
            ]
        ];

        yield 'console enabled' => [
            'config' => [
                'instrumentation' => [
                    'console' => $on
                ]
            ],
            'expectedServices' => [
                ConsoleTracingSubscriber::class
            ],
        ];

        yield 'messenger enabled with long running' => [
            'config' => [
                'instrumentation' => [
                    'messenger' => $on + ['long_running_command_enabled' => true]
                ]
            ],
            'expectedServices' => [
                MessageBusTracingMiddleware::class,
                MessengerFlushSubscriber::class,
                InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND . '.default'
            ],
        ];

        yield 'all disabled' => [
            'config' => [
                'instrumentation' => [
                    'http_server' => $off,
                    'messenger' => $off,
                    'console' => $off,
                    'doctrine' => $off,
                    'events' => $off
                ]
            ],
            'expectedServices' => [],
            'unexpectedServices' => [
                HttpRequestTracingSubscriber::class,
                TracingDbalMiddleware::class,
                TracingEventDispatcher::class,
                ConsoleTracingSubscriber::class,
                MessageBusTracingMiddleware::class
            ],
        ];

        yield 'redis and cache enabled' => [
            'config' => [
                'instrumentation' => [
                    'redis' => $on,
                    'cache' => $on
                ],
            ],
            'expectedServices' => [
                TracingPhpRedis::class,
                TracingCachePool::class
            ]
        ];

        yield 'mailer and twig enabled' => [
            'config' => [
                'instrumentation' => [
                    'mailer' => $on,
                    'twig' => $on
                ]
            ],
            'expectedServices' => [
                MailerTracingSubscriber::class,
                TraceableTwigExtension::class
            ],
        ];

        yield 'http_client and async enabled' => [
            'config' => [
                'instrumentation' => [
                    'http_client' => $on,
                    'async' => $on
                ]
            ],
            'expectedServices' => [
                HttpTracingMiddleware::class,
                AsyncTracingSubscriber::class
            ],
        ];

        yield 'traceable enabled' => [
            'config' => [
                'instrumentation' => [
                    'traceable' => $on
                ]
            ],
            'expectedServices' => [
                TraceableSubscriber::class,
                TraceableHookSubscriber::class
            ]
        ];
    }

    public static function provideServiceConfigIsAcceptedCases(): Generator
    {
        yield 'name only' => [['name' => 'my-service']];
        yield 'full config' => [['name' => 'svc', 'version' => '2.0', 'namespace' => 'ns', 'environment' => 'prod']];
        yield 'null values' => [['name' => null, 'version' => null, 'namespace' => null, 'environment' => null]];
    }
}
