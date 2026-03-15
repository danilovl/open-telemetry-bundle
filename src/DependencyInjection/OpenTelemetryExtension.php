<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\DependencyInjection;

use Danilovl\OpenTelemetryBundle\Instrumentation\Async\AsyncTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces\AsyncMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Metrics\DefaultAsyncMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Metrics\DefaultDoctrineMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\TracingDbalMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler\DefaultDoctrineSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore\DefaultDoctrineTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Metrics\DefaultRedisMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\TracingRedis;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Metrics\DefaultCacheMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\ConsoleTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Metrics\DefaultConsoleMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventDispatcherMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Metrics\DefaultEventDispatcherMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler\DefaultEventSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore\DefaultEventTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TracingEventDispatcher;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Metrics\DefaultHttpClientMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Metrics\DefaultHttpServerMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore\DefaultHttpRequestTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\MailerTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Metrics\DefaultMailerMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\{
    MessageBusTracingMiddleware,
    MessengerFlushSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\LongRunningCommand\DefaultLongRunningCommand;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Metrics\DefaultMessengerMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\{
    TraceableHookSubscriber,
    TraceableSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Metrics\DefaultTraceableMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\TraceableTwigExtension;
use Danilovl\OpenTelemetryBundle\Model\Configuration\{
    BaseInstrumentationConfig,
    InstrumentationConfig,
    MessengerInstrumentationConfig,
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\EventSubscriber\TracerShutdownSubscriber;
use OpenTelemetry\Context\ContextStorageInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\{
    MetricsRecorderInterface,
    TracingSpanServiceInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\DefaultLoggerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\{
    MetricsRecorder,
    TracingSpanService
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use LogicException;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\{
    ContainerBuilder,
    Definition,
    Reference
};
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @phpstan-import-type InstrumentationConfigArray from InstrumentationConfig
 */
class OpenTelemetryExtension extends Extension
{
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration;
        /** @var array{service: array<string, mixed>, instrumentation: InstrumentationConfigArray} $config */
        $config = $this->processConfiguration($configuration, $configs);

        $instrumentation = InstrumentationConfig::fromConfig($config['instrumentation']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->getDefinition(DefaultResourceInfoFactory::class)
            ->setArgument('$serviceConfig', $config['service']);

        $resourceDefinition = new Definition(ResourceInfo::class);
        $resourceDefinition->setFactory([new Reference(DefaultResourceInfoFactory::class), 'createResource']);
        $container->setDefinition('danilovl.open_telemetry.resource_info', $resourceDefinition);

        $this->registerInstrumentationServices($container);
        $this->validateDependencies($instrumentation);

        foreach ($this->getDisableConfigurations($instrumentation) as [$enabled, $serviceId]) {
            $this->disableIfFalse(
                container: $container,
                enabled: $enabled,
                serviceId: $serviceId,
            );
        }

        $this->registerCachedInstrumentation($container);

        foreach ($this->getInstrumentationMetricsConfigurations() as $instrumentationMetricsConfiguration) {
            $this->setInstrumentationMetricsArgument(
                container: $container,
                instrumentation: $instrumentation,
                serviceId: $instrumentationMetricsConfiguration['serviceId'],
                instrumentationKey: $instrumentationMetricsConfiguration['instrumentationKey'],
                argumentName: $instrumentationMetricsConfiguration['argumentName'],
                metricsInterface: $instrumentationMetricsConfiguration['metricsInterface'],
                defaultMetricsClass: $instrumentationMetricsConfiguration['defaultMetricsClass'],
            );
        }

        $this->registerMessengerMiddlewares($container);
        $this->registerDoctrineMiddlewares($container);
        $this->registerLongRunningCommand($container, $instrumentation->messenger);

        $container->register(TracerProviderInterface::class, TracerProviderInterface::class)
            ->setFactory([new Reference(DefaultTracerProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(\OpenTelemetry\API\Trace\TracerProviderInterface::class, TracerProviderInterface::class);

        $container->register(MeterProviderInterface::class, MeterProviderInterface::class)
            ->setFactory([new Reference(DefaultMeterProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(\OpenTelemetry\API\Metrics\MeterProviderInterface::class, MeterProviderInterface::class);

        $container->register(LoggerProviderInterface::class, LoggerProviderInterface::class)
            ->setFactory([new Reference(DefaultLoggerProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(\OpenTelemetry\API\Logs\LoggerProviderInterface::class, LoggerProviderInterface::class);

        $container->register(ContextStorageInterface::class, ContextStorageInterface::class)
            ->setFactory([\OpenTelemetry\Context\Context::class, 'storage'])
            ->setPublic(false);

        $container->register(TracingSpanServiceInterface::class, TracingSpanService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);
    }

    private function registerCachedInstrumentation(ContainerBuilder $container): void
    {
        $id = 'danilovl.open_telemetry.cached_instrumentation';
        if (!$container->hasDefinition($id)) {
            $definition = new Definition(
                class: CachedInstrumentation::class,
                arguments: ['danilovl/open-telemetry']
            );

            $container->setDefinition($id, $definition);
        }

        if (!$container->hasDefinition(CachedInstrumentation::class) && !$container->hasAlias(CachedInstrumentation::class)) {
            $container->setAlias(CachedInstrumentation::class, $id);
        }
    }

    private function registerLongRunningCommand(ContainerBuilder $container, MessengerInstrumentationConfig $messengerConfig): void
    {
        if (!$messengerConfig->longRunningCommandEnabled) {
            return;
        }

        $defaultServiceId = InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND . '.default';
        $defaultClass = DefaultLongRunningCommand::class;

        if ($container->hasDefinition($defaultServiceId)) {
            return;
        }

        $container->setDefinition($defaultServiceId, new Definition($defaultClass))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag(InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND);
    }

    private function registerDoctrineMiddlewares(ContainerBuilder $container): void
    {
        $id = TracingDbalMiddleware::class;

        if ($container->hasDefinition($id)) {
            $container->getDefinition($id)
                ->addTag('doctrine.middleware');
        }
    }

    private function registerMessengerMiddlewares(ContainerBuilder $container): void
    {
        $id = MessageBusTracingMiddleware::class;

        if ($container->hasDefinition($id)) {
            $container->getDefinition($id)
                ->addTag('messenger.middleware', ['alias' => 'messenger_tracing']);
        }
    }

    /**
     * @return array<int, array{bool, string}>
     */
    private function getDisableConfigurations(InstrumentationConfig $instrumentation): array
    {
        $httpServerEnabled = $this->isInstrumentationTracingEnabled($instrumentation->httpServer);
        $httpClientEnabled = $this->isInstrumentationTracingEnabled($instrumentation->httpClient);
        $eventsEnabled = $this->isInstrumentationTracingEnabled($instrumentation->events);
        $messengerEnabled = $this->isInstrumentationTracingEnabled($instrumentation->messenger);
        $doctrineEnabled = $this->isInstrumentationTracingEnabled($instrumentation->doctrine);
        $consoleEnabled = $this->isInstrumentationTracingEnabled($instrumentation->console);
        $cacheEnabled = $this->isInstrumentationTracingEnabled($instrumentation->cache);
        $redisEnabled = $this->isInstrumentationTracingEnabled($instrumentation->redis);
        $mailerEnabled = $this->isInstrumentationTracingEnabled($instrumentation->mailer);
        $twigEnabled = $this->isInstrumentationTracingEnabled($instrumentation->twig);
        $traceableEnabled = $this->isInstrumentationTracingEnabled($instrumentation->traceable);
        $asyncEnabled = $this->isInstrumentationTracingEnabled($instrumentation->async);

        $messengerLongRunning = $messengerEnabled && $instrumentation->messenger->longRunningCommandEnabled;

        $eventDefaultIgnore = $instrumentation->events->defaultTraceIgnoreEnabled;
        $eventSpanHandler = $instrumentation->events->defaultSpanNameHandlerEnabled;

        $doctrineDefaultIgnore =
            $instrumentation->doctrine->defaultTraceIgnoreEnabled
            && interface_exists('Doctrine\Persistence\ManagerRegistry');

        $doctrineSpanHandler = $instrumentation->doctrine->defaultSpanNameHandlerEnabled;

        $httpDefaultIgnore = $instrumentation->httpServer->defaultTraceIgnoreEnabled;

        return [
            [$cacheEnabled, TracingCachePool::class],
            [$messengerEnabled, MessageBusTracingMiddleware::class],
            [$messengerLongRunning, MessengerFlushSubscriber::class],
            [$asyncEnabled, AsyncTracingSubscriber::class],
            [$redisEnabled, TracingRedis::class],
            [$consoleEnabled, ConsoleTracingSubscriber::class],
            [$twigEnabled, TraceableTwigExtension::class],
            [$mailerEnabled, MailerTracingSubscriber::class],
            [$eventsEnabled, TracingEventDispatcher::class],
            [$eventDefaultIgnore, DefaultEventTraceIgnore::class],
            [$eventSpanHandler, DefaultEventSpanNameHandler::class],
            [$doctrineEnabled, TracingDbalMiddleware::class],
            [$doctrineDefaultIgnore, DefaultDoctrineTraceIgnore::class],
            [$doctrineSpanHandler, DefaultDoctrineSpanNameHandler::class],
            [$httpDefaultIgnore, DefaultHttpRequestTraceIgnore::class],
            [$httpServerEnabled, HttpRequestTracingSubscriber::class],
            [$httpClientEnabled, HttpTracingMiddleware::class],
            [$traceableEnabled, TraceableSubscriber::class],
            [$traceableEnabled, TraceableHookSubscriber::class],
            [true, TracerShutdownSubscriber::class],
        ];
    }

    private function setInstrumentationMetricsArgument(
        ContainerBuilder $container,
        InstrumentationConfig $instrumentation,
        string $serviceId,
        string $instrumentationKey,
        string $argumentName,
        string $metricsInterface,
        string $defaultMetricsClass,
    ): void {
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        $instrumentationConfig = $instrumentation->getByKey($instrumentationKey);
        $isEnable = $this->isInstrumentationMeteringEnabled($instrumentationConfig);

        $value = $this->createMetricsReference(
            container: $container,
            isEnable: $isEnable,
            instrumentationKey: $instrumentationKey,
            metricsInterface: $metricsInterface,
            defaultMetricsClass: $defaultMetricsClass,
        );

        $container
            ->getDefinition($serviceId)
            ->setArgument($argumentName, $value);
    }

    private function createMetricsReference(
        ContainerBuilder $container,
        bool $isEnable,
        string $instrumentationKey,
        string $metricsInterface,
        string $defaultMetricsClass,
    ): Reference {
        $defaultMetricsServiceId = 'danilovl.open_telemetry.metrics.' . $instrumentationKey . '.default';

        if (!$container->hasDefinition($defaultMetricsServiceId)) {
            $argumentValue = $this->createMetricsRecorderReference(
                container: $container,
            );

            $defaultMetricsDefinition = new Definition($defaultMetricsClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(false)
                ->setArgument(
                    key: '$isEnable',
                    value: $isEnable
                )
                ->setArgument(
                    key: '$metricsRecorder',
                    value: $argumentValue
                );

            $container->setDefinition(
                $defaultMetricsServiceId,
                $defaultMetricsDefinition
            );
        }

        if (!$container->hasDefinition($metricsInterface) && !$container->hasAlias($metricsInterface)) {
            $container->setAlias($metricsInterface, $defaultMetricsServiceId)->setPublic(false);
        }

        return new Reference($metricsInterface);
    }

    private function createMetricsRecorderReference(ContainerBuilder $container): Reference
    {
        if ($container->hasDefinition(MetricsRecorderInterface::class) || $container->hasAlias(MetricsRecorderInterface::class)) {
            return new Reference(MetricsRecorderInterface::class);
        }

        $definition = new Definition(MetricsRecorder::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false)
            ->setArgument('$meterName', $this->resolveGlobalMeterName())
            ->setArgument('$instrumentation', new Reference(CachedInstrumentation::class));

        $container->setDefinition(
            id: MetricsRecorderInterface::class,
            definition: $definition
        );

        return new Reference(MetricsRecorderInterface::class);
    }

    private function isInstrumentationEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $this->isInstrumentationTracingEnabled($instrumentationConfig) ||
            $this->isInstrumentationMeteringEnabled($instrumentationConfig);
    }

    private function registerInstrumentationServices(ContainerBuilder $container): void
    {
        $classes = [
            HttpRequestTracingSubscriber::class,
            HttpTracingMiddleware::class,
            MessageBusTracingMiddleware::class,
            MessengerFlushSubscriber::class,
            AsyncTracingSubscriber::class,
            TracingRedis::class,
            TracingCachePool::class,
            ConsoleTracingSubscriber::class,
            MailerTracingSubscriber::class,
            TracingEventDispatcher::class,
            TraceableSubscriber::class,
            TraceableHookSubscriber::class,
            TracingDbalMiddleware::class,
            TraceableTwigExtension::class,
            DefaultEventTraceIgnore::class,
            DefaultEventSpanNameHandler::class,
            DefaultDoctrineTraceIgnore::class,
            DefaultDoctrineSpanNameHandler::class,
            DefaultHttpRequestTraceIgnore::class,
        ];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $container->register($class, $class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(false);
        }
    }

    private function validateDependencies(InstrumentationConfig $instrumentation): void
    {
        foreach ($this->getInstrumentationDependencies() as $key => ['type' => $type, 'name' => $dependency, 'message' => $message]) {
            $instrumentationConfig = $instrumentation->getByKey($key);

            if (!$this->isInstrumentationEnabled($instrumentationConfig)) {
                continue;
            }

            $exists = match ($type) {
                'extension' => extension_loaded($dependency),
                'interface' => interface_exists($dependency),
                'class' => class_exists($dependency),
                default => false
            };

            if (!$exists) {
                throw new LogicException($message);
            }
        }
    }

    private function isInstrumentationTracingEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->tracingEnabled;
    }

    private function isInstrumentationMeteringEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->meteringEnabled;
    }

    private function resolveGlobalMeterName(): string
    {
        return 'danilovl/open-telemetry';
    }

    private function disableIfFalse(ContainerBuilder $container, bool $enabled, string $serviceId): void
    {
        if ($enabled || !$container->hasDefinition($serviceId)) {
            return;
        }

        $container->removeDefinition($serviceId);
    }

    /**
     * @return array<string, array{type: string, name: string, message: string}>
     */
    private function getInstrumentationDependencies(): array
    {
        return [
            'http_server' => [
                'type' => 'interface',
                'name' => 'Symfony\Component\EventDispatcher\EventDispatcherInterface',
                'message' => 'The "symfony/http-kernel" package is required for HttpServer instrumentation.'
            ],
            'redis' => [
                'type' => 'extension',
                'name' => 'redis',
                'message' => 'The "redis" extension is required for Redis instrumentation.'
            ],
            'messenger' => [
                'type' => 'interface',
                'name' => 'Symfony\Component\Messenger\MessageBusInterface',
                'message' => 'The "symfony/messenger" package is required for Messenger instrumentation.'
            ],
            'twig' => [
                'type' => 'class',
                'name' => 'Twig\Environment',
                'message' => 'The "twig/twig" package is required for Twig instrumentation.'
            ],
            'async' => [
                'type' => 'class',
                'name' => 'Danilovl\AsyncBundle\AsyncBundle',
                'message' => 'The "danilovl/async-bundle" package is required for Async instrumentation.'
            ],
            'cache' => [
                'type' => 'interface',
                'name' => 'Psr\Cache\CacheItemPoolInterface',
                'message' => 'The "symfony/cache" or "psr/cache" package is required for Cache instrumentation.'
            ],
            'doctrine' => [
                'type' => 'interface',
                'name' => 'Doctrine\DBAL\Driver',
                'message' => 'The "doctrine/dbal" or "doctrine/orm" package is required for Doctrine instrumentation.'
            ],
            'events' => [
                'type' => 'interface',
                'name' => 'Symfony\Component\EventDispatcher\EventDispatcherInterface',
                'message' => 'The "symfony/event-dispatcher" package is required for Events instrumentation.'
            ],
            'mailer' => [
                'type' => 'interface',
                'name' => 'Symfony\Component\Mailer\MailerInterface',
                'message' => 'The "symfony/mailer" package is required for Mailer instrumentation.'
            ],
            'http_client' => [
                'type' => 'interface',
                'name' => 'Symfony\Contracts\HttpClient\HttpClientInterface',
                'message' => 'The "symfony/http-client" package is required for HttpClient instrumentation.'
            ],
            'console' => [
                'type' => 'class',
                'name' => 'Symfony\Component\Console\Application',
                'message' => 'The "symfony/console" package is required for Console instrumentation.'
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     serviceId: string,
     *     instrumentationKey: string,
     *     argumentName: string,
     *     metricsInterface: string,
     *     defaultMetricsClass: string
     * }>
     */
    private function getInstrumentationMetricsConfigurations(): array
    {
        return [
            [
                'serviceId' => HttpRequestTracingSubscriber::class,
                'instrumentationKey' => 'http_server',
                'argumentName' => '$httpServerMetrics',
                'metricsInterface' => HttpServerMetricsInterface::class,
                'defaultMetricsClass' => DefaultHttpServerMetrics::class
            ],
            [
                'serviceId' => MessageBusTracingMiddleware::class,
                'instrumentationKey' => 'messenger',
                'argumentName' => '$messengerMetrics',
                'metricsInterface' => MessengerMetricsInterface::class,
                'defaultMetricsClass' => DefaultMessengerMetrics::class
            ],
            [
                'serviceId' => AsyncTracingSubscriber::class,
                'instrumentationKey' => 'async',
                'argumentName' => '$asyncMetrics',
                'metricsInterface' => AsyncMetricsInterface::class,
                'defaultMetricsClass' => DefaultAsyncMetrics::class
            ],
            [
                'serviceId' => HttpTracingMiddleware::class,
                'instrumentationKey' => 'http_client',
                'argumentName' => '$httpClientMetrics',
                'metricsInterface' => HttpClientMetricsInterface::class,
                'defaultMetricsClass' => DefaultHttpClientMetrics::class
            ],
            [
                'serviceId' => TracingRedis::class,
                'instrumentationKey' => 'redis',
                'argumentName' => '$redisMetrics',
                'metricsInterface' => RedisMetricsInterface::class,
                'defaultMetricsClass' => DefaultRedisMetrics::class
            ],
            [
                'serviceId' => TracingCachePool::class,
                'instrumentationKey' => 'cache',
                'argumentName' => '$cacheMetrics',
                'metricsInterface' => CacheMetricsInterface::class,
                'defaultMetricsClass' => DefaultCacheMetrics::class
            ],
            [
                'serviceId' => ConsoleTracingSubscriber::class,
                'instrumentationKey' => 'console',
                'argumentName' => '$consoleMetrics',
                'metricsInterface' => ConsoleMetricsInterface::class,
                'defaultMetricsClass' => DefaultConsoleMetrics::class
            ],
            [
                'serviceId' => MailerTracingSubscriber::class,
                'instrumentationKey' => 'mailer',
                'argumentName' => '$mailerMetrics',
                'metricsInterface' => MailerMetricsInterface::class,
                'defaultMetricsClass' => DefaultMailerMetrics::class
            ],
            [
                'serviceId' => TracingEventDispatcher::class,
                'instrumentationKey' => 'events',
                'argumentName' => '$eventDispatcherMetrics',
                'metricsInterface' => EventDispatcherMetricsInterface::class,
                'defaultMetricsClass' => DefaultEventDispatcherMetrics::class
            ],
            [
                'serviceId' => TraceableHookSubscriber::class,
                'instrumentationKey' => 'traceable',
                'argumentName' => '$traceableMetrics',
                'metricsInterface' => TraceableMetricsInterface::class,
                'defaultMetricsClass' => DefaultTraceableMetrics::class
            ],
            [
                'serviceId' => TracingDbalMiddleware::class,
                'instrumentationKey' => 'doctrine',
                'argumentName' => '$doctrineMetrics',
                'metricsInterface' => DoctrineMetricsInterface::class,
                'defaultMetricsClass' => DefaultDoctrineMetrics::class
            ],
        ];
    }
}
