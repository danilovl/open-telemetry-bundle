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
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\TraceIgnore\MessengerConsumeTraceIgnore;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\SpanNameHandler\DefaultMessengerSpanNameHandler;
use OpenTelemetry\Context\{Context, ContextStorageInterface};
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

        $this->validateDependencies($instrumentation);

        $this->registerInstrumentationServices($container, $instrumentation);

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
            ->setFactory([Context::class, 'storage'])
            ->setPublic(false);

        $container->register(TracingSpanServiceInterface::class, TracingSpanService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);
    }

    private function registerInstrumentationServices(ContainerBuilder $container, InstrumentationConfig $instrumentation): void
    {
        $deps = $this->getInstrumentationDependencies();

        if ($this->isInstrumentationEnabled($instrumentation->httpClient) && $this->checkDependency($deps['http_client'])) {
            $container->register(HttpTracingMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->messenger) && $this->checkDependency($deps['messenger'])) {
            $container->register(MessageBusTracingMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('messenger.middleware', ['alias' => 'messenger_tracing']);

            $container->register(DefaultMessengerSpanNameHandler::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);

            if ($instrumentation->messenger->longRunningCommandEnabled) {
                $container->register(MessengerFlushSubscriber::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->mailer) && $this->checkDependency($deps['mailer'])) {
            $container->register(MailerTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->twig) && $this->checkDependency($deps['twig'])) {
            $container->register(TraceableTwigExtension::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->redis) && $this->checkDependency($deps['redis'])) {
            $container->register(TracingRedis::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->async) && $this->checkDependency($deps['async'])) {
            $container->register(AsyncTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->doctrine) && $this->checkDependency($deps['doctrine'])) {
            $container->register(TracingDbalMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('doctrine.middleware');

            if ($instrumentation->doctrine->defaultTraceIgnoreEnabled) {
                $container->register(DefaultDoctrineTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }

            if ($instrumentation->doctrine->defaultSpanNameHandlerEnabled) {
                $container->register(DefaultDoctrineSpanNameHandler::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->cache)) {
            $container->register(TracingCachePool::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->events)) {
            $container->register(TracingEventDispatcher::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);

            if ($instrumentation->events->defaultTraceIgnoreEnabled) {
                $container->register(DefaultEventTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }

            if ($instrumentation->events->defaultSpanNameHandlerEnabled) {
                $container->register(DefaultEventSpanNameHandler::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->console) && $this->checkDependency($deps['console'])) {
            $container->register(ConsoleTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);

            if ($this->isInstrumentationEnabled($instrumentation->messenger) && $this->checkDependency($deps['messenger'])) {
                $container->register(MessengerConsumeTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->traceable)) {
            $container->register(TraceableSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);

            $container->register(TraceableHookSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        if ($this->isInstrumentationEnabled($instrumentation->httpServer)) {
            $container->register(HttpRequestTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);

            if ($instrumentation->httpServer->defaultTraceIgnoreEnabled) {
                $container->register(DefaultHttpRequestTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        }
    }

    /**
     * @param array{type: string, name: string} $dep
     */
    private function checkDependency(array $dep): bool
    {
        /** @var string $type */
        $type = $dep['type'];
        /** @var string $dependency */
        $dependency = $dep['name'];

        return match ($type) {
            'extension' => extension_loaded($dependency),
            'interface' => interface_exists($dependency),
            'class' => class_exists($dependency),
            default => false
        };
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

    private function isInstrumentationMeteringEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->meteringEnabled;
    }

    private function isInstrumentationTracingEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->tracingEnabled;
    }

    private function resolveGlobalMeterName(): string
    {
        return 'danilovl/open-telemetry';
    }

    /**
     * @return array<string, array{type: string, name: string, message: string}>
     */
    private function getInstrumentationDependencies(): array
    {
        return [
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
            'doctrine' => [
                'type' => 'interface',
                'name' => 'Doctrine\DBAL\Driver',
                'message' => 'The "doctrine/dbal" or "doctrine/orm" package is required for Doctrine instrumentation.'
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
