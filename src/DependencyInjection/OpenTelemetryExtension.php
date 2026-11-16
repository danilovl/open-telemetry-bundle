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
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    AbstractRedisHookInstrumentation,
    PhpRedisHookInstrumentation,
    PredisHookInstrumentation,
    RedisHookSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Metrics\DefaultRedisMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Metrics\DefaultCacheMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\ConsoleTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Metrics\DefaultConsoleMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\TraceIgnore\MessengerConsumeTraceIgnore;
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
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\SpanNameHandler\DefaultMessengerSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\{
    TraceableHookSubscriber,
    TraceableSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Metrics\DefaultTraceableMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\TraceableTwigExtension;
use Danilovl\OpenTelemetryBundle\Model\Configuration\{
    BaseInstrumentationConfig,
    InstrumentationConfig
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\{
    MetricsRecorderInterface,
    TracerProviderFactoryInterface,
    TracingSpanServiceInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Log\{
    LogRecordExporterInterface as BundleLogRecordExporterInterface,
    LogRecordProcessorInterface as BundleLogRecordProcessorInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Metric\{
    MetricReaderInterface,
    MetricExporterInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\Trace\{
    TraceSpanExporterInterface,
    TraceSpanProcessorInterface
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
use OpenTelemetry\API\Logs\LoggerProviderInterface as APILoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface as APIMeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface as APITracerProviderInterface;
use OpenTelemetry\Context\{
    Context,
    ContextStorageInterface
};
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
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @phpstan-import-type InstrumentationConfigArray from InstrumentationConfig
 */
class OpenTelemetryExtension extends Extension
{
    public const string PARAMETER_SENSITIVE_QUERY_PARAMETERS = 'danilovl.open_telemetry.privacy.sensitive_query_parameters';
    public const string PARAMETER_SANITIZE_SQL = 'danilovl.open_telemetry.privacy.sanitize_sql';
    public const string REDIS_HOOK_TAG = 'danilovl.open_telemetry.redis.hook';

    /**
     * @var array<string, string>
     */
    public const array INSTRUMENTATION_SCOPES = [
        'async' => AsyncTracingSubscriber::INSTRUMENTATION_NAME,
        'cache' => TracingCachePool::INSTRUMENTATION_NAME,
        'console' => ConsoleTracingSubscriber::INSTRUMENTATION_NAME,
        'doctrine' => TracingDbalMiddleware::INSTRUMENTATION_NAME,
        'events' => TracingEventDispatcher::INSTRUMENTATION_NAME,
        'http_client' => HttpTracingMiddleware::INSTRUMENTATION_NAME,
        'http_server' => HttpRequestTracingSubscriber::INSTRUMENTATION_NAME,
        'mailer' => MailerTracingSubscriber::INSTRUMENTATION_NAME,
        'messenger' => MessageBusTracingMiddleware::INSTRUMENTATION_NAME,
        'redis' => AbstractRedisHookInstrumentation::INSTRUMENTATION_NAME,
        'predis' => AbstractRedisHookInstrumentation::INSTRUMENTATION_NAME,
        'traceable' => TraceableSubscriber::INSTRUMENTATION_NAME,
        'twig' => TraceableTwigExtension::INSTRUMENTATION_NAME,
    ];

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
        /** @var array{service: array<string, mixed>, privacy: array{sensitive_query_parameters: list<string>, sanitize_sql: bool}, instrumentation: array<string, array<string, mixed>>} $config */
        $config = $this->processConfiguration($configuration, $configs);

        /** @var InstrumentationConfigArray $instrumentationArray */
        $instrumentationArray = $this->resolveEnabledFlags($config['instrumentation']);
        $instrumentation = InstrumentationConfig::fromConfig($instrumentationArray);

        $container->setParameter(self::PARAMETER_SENSITIVE_QUERY_PARAMETERS, $config['privacy']['sensitive_query_parameters']);
        $container->setParameter(self::PARAMETER_SANITIZE_SQL, $config['privacy']['sanitize_sql']);

        $fileLocator = new FileLocator(__DIR__ . '/../Resources/config');
        $loader = new YamlFileLoader($container, $fileLocator);
        $loader->load('services.yaml');

        $container
            ->getDefinition(DefaultResourceInfoFactory::class)
            ->setArgument('$serviceConfig', $config['service']);

        $resourceDefinition = new Definition(ResourceInfo::class);
        $resourceDefinition->setFactory([new Reference(DefaultResourceInfoFactory::class), 'createResource']);
        $container->setDefinition('danilovl.open_telemetry.resource_info', $resourceDefinition);

        $this->registerScopedInstrumentation($container, $instrumentation);
        $this->registerInstrumentationServices($container, $instrumentation);

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

        $container
            ->setAlias(TracerProviderFactoryInterface::class, DefaultTracerProviderFactory::class)
            ->setPublic(false);

        $container
            ->register(TracerProviderInterface::class, TracerProviderInterface::class)
            ->setFactory([new Reference(DefaultTracerProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(APITracerProviderInterface::class, TracerProviderInterface::class);

        $container
            ->register(MeterProviderInterface::class, MeterProviderInterface::class)
            ->setFactory([new Reference(DefaultMeterProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(APIMeterProviderInterface::class, MeterProviderInterface::class);

        $container
            ->register(LoggerProviderInterface::class, LoggerProviderInterface::class)
            ->setFactory([new Reference(DefaultLoggerProviderFactory::class), 'create'])
            ->setPublic(false);

        $container->setAlias(APILoggerProviderInterface::class, LoggerProviderInterface::class);

        $container
            ->register(ContextStorageInterface::class, ContextStorageInterface::class)
            ->setFactory([Context::class, 'storage'])
            ->setPublic(false);

        $container
            ->register(TracingSpanServiceInterface::class, TracingSpanService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $this->registerProviderAutoconfiguration($container);
    }

    /**
     * @param array<string, array<string, mixed>> $instrumentationConfig
     * @return array<string, array<string, mixed>>
     */
    private function resolveEnabledFlags(array $instrumentationConfig): array
    {
        foreach ($instrumentationConfig as $key => $config) {
            $enabled = $config['enabled'] ?? null;
            $available = $this->isDependencyAvailable($key);

            if ($enabled === true && !$available) {
                throw new LogicException($this->getDependencyMessage($key));
            }

            $instrumentationConfig[$key]['enabled'] = $enabled ?? $available;
        }

        return $instrumentationConfig;
    }

    private function registerInstrumentationServices(
        ContainerBuilder $container,
        InstrumentationConfig $instrumentation,
    ): void {
        $sensitiveQueryParameters = '%' . self::PARAMETER_SENSITIVE_QUERY_PARAMETERS . '%';

        if ($this->isInstrumentationEnabled($instrumentation->httpClient)) {
            $container
                ->register(HttpTracingMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('http_client'))
                ->setArgument('$sensitiveQueryParameters', $sensitiveQueryParameters);
        }

        if ($this->isInstrumentationEnabled($instrumentation->messenger)) {
            $container
                ->register(MessageBusTracingMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('messenger'))
                ->addTag('messenger.middleware', ['alias' => 'messenger_tracing']);

            $container
                ->register(DefaultMessengerSpanNameHandler::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag(InstrumentationTags::MESSENGER_SPAN_NAME_HANDLER);

            if ($instrumentation->messenger->longRunningCommandEnabled) {
                $container
                    ->register(MessengerFlushSubscriber::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);

                $container
                    ->register(DefaultLongRunningCommand::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->mailer)) {
            $container
                ->register(MailerTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('mailer'));
        }

        if ($this->isInstrumentationEnabled($instrumentation->twig)) {
            $container
                ->register(TraceableTwigExtension::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('twig'));
        }

        $redisHooksRegistered = false;

        if ($this->isInstrumentationEnabled($instrumentation->redis)) {
            $container
                ->register(PhpRedisHookInstrumentation::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('redis'))
                ->addTag(self::REDIS_HOOK_TAG);

            $redisHooksRegistered = true;
        }

        if ($this->isInstrumentationEnabled($instrumentation->predis)) {
            $container
                ->register(PredisHookInstrumentation::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('predis'))
                ->addTag(self::REDIS_HOOK_TAG);

            $redisHooksRegistered = true;
        }

        if ($redisHooksRegistered) {
            $container
                ->register(RedisHookSubscriber::class)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentations', new TaggedIteratorArgument(self::REDIS_HOOK_TAG));
        }

        if ($this->isInstrumentationEnabled($instrumentation->async)) {
            $container
                ->register(AsyncTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('async'));
        }

        if ($this->isInstrumentationEnabled($instrumentation->doctrine)) {
            $container
                ->register(TracingDbalMiddleware::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('doctrine'))
                ->setArgument('$sanitizeSql', '%' . self::PARAMETER_SANITIZE_SQL . '%')
                ->addTag('doctrine.middleware');

            if ($instrumentation->doctrine->defaultTraceIgnoreEnabled) {
                $container
                    ->register(DefaultDoctrineTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::DOCTRINE_TRACE_IGNORE);
            }

            if ($instrumentation->doctrine->defaultSpanNameHandlerEnabled) {
                $container
                    ->register(DefaultDoctrineSpanNameHandler::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::DOCTRINE_SPAN_NAME_HANDLER);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->cache)) {
            $container
                ->register(TracingCachePool::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('cache'))
                ->setArgument('$poolName', 'cache.app');
        }

        if ($this->isInstrumentationEnabled($instrumentation->events)) {
            $container
                ->register(TracingEventDispatcher::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('events'));

            if ($instrumentation->events->defaultTraceIgnoreEnabled) {
                $container
                    ->register(DefaultEventTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::EVENT_TRACE_IGNORE);
            }

            if ($instrumentation->events->defaultSpanNameHandlerEnabled) {
                $container
                    ->register(DefaultEventSpanNameHandler::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::EVENT_SPAN_NAME_HANDLER);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->console)) {
            $container
                ->register(ConsoleTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('console'));

            if ($this->isInstrumentationEnabled($instrumentation->messenger)) {
                $container
                    ->register(MessengerConsumeTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::CONSOLE_TRACE_IGNORE);
            }
        }

        if ($this->isInstrumentationEnabled($instrumentation->traceable)) {
            $container
                ->register(TraceableSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('traceable'));

            $container
                ->register(TraceableHookSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('traceable'));
        }

        if ($this->isInstrumentationEnabled($instrumentation->httpServer)) {
            $container
                ->register(HttpRequestTracingSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$instrumentation', $this->scopeReference('http_server'))
                ->setArgument('$sensitiveQueryParameters', $sensitiveQueryParameters);

            if ($instrumentation->httpServer->defaultTraceIgnoreEnabled) {
                $container
                    ->register(DefaultHttpRequestTraceIgnore::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true)
                    ->addTag(InstrumentationTags::HTTP_REQUEST_TRACE_IGNORE);
            }
        }
    }

    public static function scopeServiceId(string $instrumentationKey): string
    {
        return 'danilovl.open_telemetry.instrumentation.' . $instrumentationKey;
    }

    private function scopeReference(string $instrumentationKey): Reference
    {
        return new Reference(self::scopeServiceId($instrumentationKey));
    }

    private function registerScopedInstrumentation(
        ContainerBuilder $container,
        InstrumentationConfig $instrumentation,
    ): void {
        $id = 'danilovl.open_telemetry.cached_instrumentation';
        if (!$container->hasDefinition($id)) {
            $container->setDefinition($id, new Definition(CachedInstrumentation::class, ['danilovl/open-telemetry']));
        }

        if (!$container->hasDefinition(CachedInstrumentation::class) && !$container->hasAlias(CachedInstrumentation::class)) {
            $container->setAlias(CachedInstrumentation::class, $id);
        }

        foreach (self::INSTRUMENTATION_SCOPES as $key => $scopeName) {
            $scopeId = self::scopeServiceId($key);

            if ($container->hasDefinition($scopeId)) {
                continue;
            }

            $definition = new Definition(ScopedInstrumentation::class, [
                new Definition(CachedInstrumentation::class, [$scopeName]),
                $this->isInstrumentationTracingEnabled($instrumentation->getByKey($key)),
            ]);

            $container->setDefinition($scopeId, $definition);
        }
    }

    /**
     * @param array<string, string> $extraArguments
     */
    private function setInstrumentationMetricsArgument(
        ContainerBuilder $container,
        InstrumentationConfig $instrumentation,
        string $serviceId,
        string $instrumentationKey,
        string $argumentName,
        string $metricsInterface,
        string $defaultMetricsClass,
        array $extraArguments = [],
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
            extraArguments: $extraArguments,
        );

        $container
            ->getDefinition($serviceId)
            ->setArgument($argumentName, $value);
    }

    public static function defaultMetricsServiceId(string $instrumentationKey): string
    {
        return 'danilovl.open_telemetry.metrics.' . $instrumentationKey . '.default';
    }

    /**
     * When an interface is shared (redis + predis), the second area references its own default directly.
     *
     * @param array<string, string> $extraArguments
     */
    private function createMetricsReference(
        ContainerBuilder $container,
        bool $isEnable,
        string $instrumentationKey,
        string $metricsInterface,
        string $defaultMetricsClass,
        array $extraArguments = [],
    ): Reference {
        $defaultMetricsServiceId = self::defaultMetricsServiceId($instrumentationKey);

        if (!$container->hasDefinition($defaultMetricsServiceId)) {
            $defaultMetricsDefinition = new Definition($defaultMetricsClass)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(false)
                ->setArgument('$isEnable', $isEnable)
                ->setArgument('$metricsRecorder', $this->createMetricsRecorderReference($container));

            foreach ($extraArguments as $key => $value) {
                $defaultMetricsDefinition->setArgument($key, $value);
            }

            $container->setDefinition($defaultMetricsServiceId, $defaultMetricsDefinition);
        }

        if (!$container->hasDefinition($metricsInterface) && !$container->hasAlias($metricsInterface)) {
            $container->setAlias($metricsInterface, $defaultMetricsServiceId)->setPublic(false);

            return new Reference($metricsInterface);
        }

        if ($container->hasAlias($metricsInterface) && (string) $container->getAlias($metricsInterface) === $defaultMetricsServiceId) {
            return new Reference($metricsInterface);
        }

        return new Reference($defaultMetricsServiceId);
    }

    /**
     * Ensures a single shared MetricsRecorder service exists and returns a Reference to it.
     *
     * MetricsRecorder is shared across all instrumentation areas (http, messenger, cache, etc.)
     * so that all metrics flow through the same MeterProvider instance.
     * Registered manually (autowired: false) to prevent accidental duplicate definitions
     * and to have full control over the constructor arguments.
     */
    private function createMetricsRecorderReference(ContainerBuilder $container): Reference
    {
        if ($container->hasDefinition(MetricsRecorderInterface::class) || $container->hasAlias(MetricsRecorderInterface::class)) {
            return new Reference(MetricsRecorderInterface::class);
        }

        $definition = new Definition(MetricsRecorder::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false)
            ->setArgument('$meterName', 'danilovl/open-telemetry')
            ->setArgument('$instrumentation', new Reference(CachedInstrumentation::class));

        $container->setDefinition(MetricsRecorderInterface::class, $definition);

        return new Reference(MetricsRecorderInterface::class);
    }

    private function isInstrumentationEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $this->isInstrumentationTracingEnabled($instrumentationConfig) ||
            $this->isInstrumentationMeteringEnabled($instrumentationConfig);
    }

    private function isInstrumentationMeteringEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->meteringEnabled;
    }

    private function isInstrumentationTracingEnabled(BaseInstrumentationConfig $instrumentationConfig): bool
    {
        return $instrumentationConfig->enabled && $instrumentationConfig->tracingEnabled;
    }

    private function isDependencyAvailable(string $instrumentationKey): bool
    {
        return match ($instrumentationKey) {
            'redis' => $this->extensionLoaded('redis') && $this->hookApiAvailable(),
            'predis' => $this->interfaceExists('Predis\ClientInterface') && $this->hookApiAvailable(),
            'messenger' => $this->interfaceExists('Symfony\Component\Messenger\MessageBusInterface'),
            'twig' => $this->classExists('Twig\Environment'),
            'async' => $this->classExists('Danilovl\AsyncBundle\AsyncBundle'),
            'doctrine' => $this->interfaceExists('Doctrine\DBAL\Driver'),
            'mailer' => $this->interfaceExists('Symfony\Component\Mailer\MailerInterface'),
            // http-client-contracts without symfony/http-client is not enough
            'http_client' => $this->interfaceExists('Symfony\Contracts\HttpClient\HttpClientInterface')
                && $this->traitExists('Symfony\Component\HttpClient\AsyncDecoratorTrait'),
            'console' => $this->classExists('Symfony\Component\Console\Application'),
            default => true,
        };
    }

    private function getDependencyMessage(string $instrumentationKey): string
    {
        return match ($instrumentationKey) {
            'redis' => 'Redis instrumentation requires the "redis" and "opentelemetry" PHP extensions.',
            'predis' => 'Predis instrumentation requires the "predis/predis" package and the "opentelemetry" PHP extension.',
            'messenger' => 'The "symfony/messenger" package is required for Messenger instrumentation.',
            'twig' => 'The "twig/twig" package is required for Twig instrumentation.',
            'async' => 'The "danilovl/async-bundle" package is required for Async instrumentation.',
            'doctrine' => 'The "doctrine/dbal" or "doctrine/orm" package is required for Doctrine instrumentation.',
            'mailer' => 'The "symfony/mailer" package is required for Mailer instrumentation.',
            'http_client' => 'The "symfony/http-client" package is required for HttpClient instrumentation.',
            'console' => 'The "symfony/console" package is required for Console instrumentation.',
            default => sprintf('Instrumentation "%s" is not available.', $instrumentationKey),
        };
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
    public static function getInstrumentationMetricsConfigurations(): array
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
                'serviceId' => PhpRedisHookInstrumentation::class,
                'instrumentationKey' => 'redis',
                'argumentName' => '$redisMetrics',
                'metricsInterface' => RedisMetricsInterface::class,
                'defaultMetricsClass' => DefaultRedisMetrics::class
            ],
            [
                'serviceId' => PredisHookInstrumentation::class,
                'instrumentationKey' => 'predis',
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

    /**
     * Registers autoconfiguration rules so that any service implementing one of the
     * bundle's processor/exporter/reader interfaces is automatically tagged.
     *
     * This means developers only need to implement the interface — no manual tag
     * configuration in services.yaml is required. OpenTelemetryCompilerPass then
     * collects all tagged services and injects them (sorted by priority) into the
     * corresponding provider factory (TracerProvider, MeterProvider, LoggerProvider).
     */
    private function registerProviderAutoconfiguration(ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(TraceSpanProcessorInterface::class)
            ->addTag(InstrumentationTags::SPAN_PROCESSOR);

        $container
            ->registerForAutoconfiguration(TraceSpanExporterInterface::class)
            ->addTag(InstrumentationTags::SPAN_EXPORTER);

        $container
            ->registerForAutoconfiguration(BundleLogRecordProcessorInterface::class)
            ->addTag(InstrumentationTags::LOG_PROCESSOR);

        $container
            ->registerForAutoconfiguration(BundleLogRecordExporterInterface::class)
            ->addTag(InstrumentationTags::LOG_EXPORTER);

        $container
            ->registerForAutoconfiguration(MetricExporterInterface::class)
            ->addTag(InstrumentationTags::METRIC_EXPORTER);

        $container
            ->registerForAutoconfiguration(MetricReaderInterface::class)
            ->addTag(InstrumentationTags::METRIC_READER);
    }

    protected function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    protected function interfaceExists(string $name, bool $autoload = true): bool
    {
        return interface_exists($name, $autoload);
    }

    protected function classExists(string $name, bool $autoload = true): bool
    {
        return class_exists($name, $autoload);
    }

    protected function traitExists(string $name, bool $autoload = true): bool
    {
        return trait_exists($name, $autoload);
    }

    protected function hookApiAvailable(): bool
    {
        return AbstractRedisHookInstrumentation::isHookApiAvailable();
    }
}
