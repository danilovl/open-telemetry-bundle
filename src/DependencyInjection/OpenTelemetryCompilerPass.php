<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\DependencyInjection;

use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces\AsyncMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventDispatcherMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TracingEventDispatcher;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    TracingRedis,
    TracingPhpRedis
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\MessageBusTracingMiddleware;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use LogicException;
use Symfony\Component\DependencyInjection\{
    Reference,
    Definition,
    ContainerBuilder
};
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class OpenTelemetryCompilerPass implements CompilerPassInterface
{
    private const string MESSAGE_BUS_MIDDLEWARE = MessageBusTracingMiddleware::class;
    private const string DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER = 'messenger.bus.default.middleware';

    public function process(ContainerBuilder $container): void
    {
        $this->registerMessengerMiddleware($container);
        $this->registerHttpClientDecorator($container);
        $this->registerEventDispatcherDecorator($container);
        $this->registerCacheDecorator($container);
        $this->registerRedisDecorator($container);
        $this->registerPRedisDecorator($container);
        $this->overrideInterfaceAliasesByUserImplementations($container);
        $this->registerTracerProviderProcessors($container);
        $this->registerLoggerProviderProcessors($container);
        $this->registerMeterProviderReaders($container);
    }

    /**
     * Injects tagged span processors and exporters into the TracerProvider service.
     *
     * The TracerProviderInterface service is backed by a factory (DefaultTracerProviderFactory).
     * Calling setArgument() on a factory-backed service definition passes named arguments
     * directly to the factory method — DefaultTracerProviderFactory::create($processors, $exporters) —
     * not to the SDK TracerProvider constructor.
     */
    private function registerTracerProviderProcessors(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TracerProviderInterface::class)) {
            return;
        }

        $definition = $container->getDefinition(TracerProviderInterface::class);

        $processors = $this->collectSortedReferences($container, InstrumentationTags::SPAN_PROCESSOR);
        if (count($processors) > 0) {
            $definition->setArgument('$processors', $processors);
        }

        $exporters = $this->collectSortedReferences($container, InstrumentationTags::SPAN_EXPORTER);
        if (count($exporters) > 0) {
            $definition->setArgument('$exporters', $exporters);
        }
    }

    /**
     * Injects tagged log processors and exporters into the LoggerProvider service.
     *
     * The LoggerProviderInterface service is backed by a factory (DefaultLoggerProviderFactory).
     * Calling setArgument() on a factory-backed service definition passes named arguments
     * directly to the factory method — DefaultLoggerProviderFactory::create($processors, $exporters) —
     * not to the SDK LoggerProvider constructor.
     */
    private function registerLoggerProviderProcessors(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(LoggerProviderInterface::class)) {
            return;
        }

        $definition = $container->getDefinition(LoggerProviderInterface::class);

        $processors = $this->collectSortedReferences($container, InstrumentationTags::LOG_PROCESSOR);
        if (count($processors) > 0) {
            $definition->setArgument('$processors', $processors);
        }

        $exporters = $this->collectSortedReferences($container, InstrumentationTags::LOG_EXPORTER);
        if (count($exporters) > 0) {
            $definition->setArgument('$exporters', $exporters);
        }
    }

    /**
     * Injects tagged metric exporters and readers into the MeterProvider service.
     *
     * The MeterProviderInterface service is backed by a factory (DefaultMeterProviderFactory).
     * Calling setArgument() on a factory-backed service definition passes named arguments
     * directly to the factory method — DefaultMeterProviderFactory::create($exporters, $readers) —
     * not to the SDK MeterProvider constructor.
     */
    private function registerMeterProviderReaders(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MeterProviderInterface::class)) {
            return;
        }

        $definition = $container->getDefinition(MeterProviderInterface::class);

        $exporters = $this->collectSortedReferences($container, InstrumentationTags::METRIC_EXPORTER);
        if (count($exporters) > 0) {
            $definition->setArgument('$exporters', $exporters);
        }

        $readers = $this->collectSortedReferences($container, InstrumentationTags::METRIC_READER);
        if (count($readers) > 0) {
            $definition->setArgument('$readers', $readers);
        }
    }

    /**
     * @return Reference[]
     */
    private function collectSortedReferences(ContainerBuilder $container, string $tag): array
    {
        $items = [];

        foreach ($container->findTaggedServiceIds($tag) as $id => $tagAttributes) {
            $priority = (int) ($tagAttributes[0]['priority'] ?? 0);
            $items[] = ['priority' => $priority, 'reference' => new Reference($id)];
        }

        usort($items, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_column($items, 'reference');
    }

    private function registerMessengerMiddleware(ContainerBuilder $container): void
    {
        $middlewareIds = [
            self::MESSAGE_BUS_MIDDLEWARE
        ];

        // Inject middleware into the default bus declared via the framework.messenger.default_bus config parameter.
        // This parameter holds a plain array of ['id' => 'service_id'] entries (not References),
        // so appendMiddleware() prepends an ['id' => ...] entry instead of a Reference object.
        if ($container->hasParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER)) {
            $middleware = $container->getParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER);

            if (is_array($middleware)) {
                foreach ($middlewareIds as $middlewareId) {
                    $middleware = $this->appendMiddleware(
                        container: $container,
                        middleware: $middleware,
                        middlewareId: $middlewareId
                    );
                }

                $container->setParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER, $middleware);
            }
        }

        // Inject middleware into every service tagged as messenger.bus.
        // Symfony may represent the middleware list either as an IteratorArgument (lazy iterator)
        // or as a plain array depending on how the bus was defined.
        // In both cases we prepend our tracing middleware to ensure it wraps all subsequent handlers.
        foreach ($container->findTaggedServiceIds('messenger.bus') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $middleware = $definition->getArgument(0);

            if ($middleware instanceof IteratorArgument) {
                $values = $middleware->getValues();

                foreach ($middlewareIds as $middlewareId) {
                    $values = $this->appendMiddlewareToBus($container, $values, $middlewareId);
                }

                $middleware->setValues($values);
            } elseif (is_array($middleware)) {
                foreach ($middlewareIds as $middlewareId) {
                    $middleware = $this->appendMiddlewareToBus($container, $middleware, $middlewareId);
                }
                $definition->setArgument(0, $middleware);
            }
        }
    }

    private function registerHttpClientDecorator(ContainerBuilder $container): void
    {
        $templateId = HttpTracingMiddleware::class;
        if (!$container->hasDefinition($templateId)) {
            return;
        }

        // HttpTracingMiddleware is registered as a template definition during extension load.
        // Here we clone it for every tagged HTTP client so each client gets its own
        // independent decorator instance pointing to the correct inner service.
        // Priority 1_000 ensures our decorator wraps any other existing decorators.
        $templateDefinition = $container->getDefinition($templateId);

        foreach ($container->findTaggedServiceIds('http_client.client') as $id => $tags) {
            if ($id === $templateId) {
                continue;
            }

            $newId = $id . '.otel_tracing';
            $definition = clone $templateDefinition;
            $definition->setDecoratedService($id, $id . '.inner', 1_000);
            $definition->setArgument('$client', new Reference($id . '.inner'));
            $definition->setPublic(false);

            $container->setDefinition($newId, $definition);
        }
    }

    private function registerEventDispatcherDecorator(ContainerBuilder $container): void
    {
        $id = TracingEventDispatcher::class;
        if (!$container->hasDefinition($id) || !$container->hasDefinition('event_dispatcher')) {
            return;
        }

        $container
            ->getDefinition($id)
            ->setDecoratedService('event_dispatcher');
    }

    private function registerCacheDecorator(ContainerBuilder $container): void
    {
        $id = TracingCachePool::class;
        if (!$container->hasDefinition($id) || !$container->hasDefinition('cache.app')) {
            return;
        }

        $container
            ->getDefinition($id)
            ->setDecoratedService('cache.app');
    }

    private function registerRedisDecorator(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TracingPhpRedis::class)) {
            return;
        }

        // Scan all container definitions to find services whose resolved class extends the native Redis extension class.
        // We skip our own bundle services and abstract definitions to avoid infinite decoration loops.
        // For each matching service we clone the TracingPhpRedis template definition and register it as a decorator,
        // injecting the original service as '$redis' via the '.inner' alias that Symfony creates automatically.
        $registered = false;
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if (
                $definition->isAbstract() ||
                str_starts_with($serviceId, 'danilovl.open_telemetry') ||
                str_starts_with($serviceId, 'Danilovl\OpenTelemetryBundle')
            ) {
                continue;
            }

            $class = $this->resolveDefinitionClass($container, $serviceId);
            if ($class === null) {
                continue;
            }

            if (!is_a($class, 'Redis', true)) {
                continue;
            }

            $decoratorId = $serviceId . '.otel_tracing';
            $container
                ->setDefinition($decoratorId, clone $container->getDefinition(TracingPhpRedis::class))
                ->setDecoratedService($serviceId)
                ->setArgument('$redis', new Reference($decoratorId . '.inner'));

            // Only the first found Redis service becomes the "default" alias,
            // so type-hinted injections of Redis resolve to a traced instance.
            if (!$registered) {
                $this->registerDefaultRedisAlias($container, 'Redis', $decoratorId);
                $registered = true;
            }
        }

        // If no native Redis services were found but Predis services exist,
        // throw a descriptive error to guide the developer to use the correct instrumentation type.
        if (!$registered) {
            $predisCount = 0;
            foreach ($container->getDefinitions() as $serviceId => $definition) {
                if (
                    $definition->isAbstract() ||
                    str_starts_with($serviceId, 'danilovl.open_telemetry') ||
                    str_starts_with($serviceId, 'Danilovl\OpenTelemetryBundle')
                ) {
                    continue;
                }

                $class = $this->resolveDefinitionClass($container, $serviceId);
                if ($class !== null && is_a($class, 'Predis\ClientInterface', true)) {
                    $predisCount++;
                }
            }

            if ($predisCount > 0) {
                $message = sprintf(
                    'Redis instrumentation is enabled but no such services were found. However, %d services of type "predis" were found.',
                    $predisCount
                );

                throw new LogicException($message);
            }

            throw new LogicException('Redis instrumentation is enabled but no Redis services were found in the container.');
        }
    }

    private function registerPRedisDecorator(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TracingRedis::class)) {
            return;
        }

        $registered = false;
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if (
                $definition->isAbstract() ||
                str_starts_with($serviceId, 'danilovl.open_telemetry') ||
                str_starts_with($serviceId, 'Danilovl\OpenTelemetryBundle')
            ) {
                continue;
            }

            $class = $this->resolveDefinitionClass($container, $serviceId);
            if ($class === null) {
                continue;
            }

            if (!is_a($class, 'Predis\ClientInterface', true)) {
                continue;
            }

            $decoratorId = $serviceId . '.otel_tracing';
            $cloneTracingRedis = clone $container->getDefinition(TracingRedis::class);

            $container
                ->setDefinition($decoratorId, $cloneTracingRedis)
                ->setDecoratedService($serviceId)
                ->setArgument('$redis', new Reference($decoratorId . '.inner'));

            if (!$registered) {
                $this->registerDefaultRedisAlias($container, 'Predis\ClientInterface', $decoratorId);
                $registered = true;
            }
        }
    }

    private function registerDefaultRedisAlias(
        ContainerBuilder $container,
        string $targetClass,
        string $decoratorId
    ): void {
        $aliasId = 'danilovl.open_telemetry.instrumentation.redis.default';
        $container
            ->setAlias($aliasId, $decoratorId)
            ->setPublic(true);

        if (!$container->hasAlias($targetClass) && !$container->hasDefinition($targetClass)) {
            $container
                ->setAlias($targetClass, $decoratorId)
                ->setPublic(true);
        }
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function hasMiddlewareReference(array $middleware, string $middlewareId): bool
    {
        foreach ($middleware as $middlewareItem) {
            $id = null;

            if ($middlewareItem instanceof Reference) {
                $id = (string) $middlewareItem;
            } elseif ($middlewareItem instanceof Definition) {
                $id = $middlewareItem->getClass();
            } elseif (is_array($middlewareItem)) {
                $id = $middlewareItem['id'] ?? null;
            }

            if (in_array($id, [$middlewareId, self::MESSAGE_BUS_MIDDLEWARE], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $middleware
     *
     * @return array<int, mixed>
     */
    private function appendMiddlewareToBus(ContainerBuilder $container, array $middleware, string $middlewareId): array
    {
        if (!$container->hasDefinition($middlewareId) || $this->hasMiddlewareReference($middleware, $middlewareId)) {
            return $middleware;
        }

        array_unshift($middleware, new Reference($middlewareId));

        return $middleware;
    }

    /**
     * @param array<int, mixed> $middleware
     *
     * @return array<int, mixed>
     */
    private function appendMiddleware(ContainerBuilder $container, array $middleware, string $middlewareId): array
    {
        if (!$container->hasDefinition($middlewareId) || $this->hasMiddleware($middleware, $middlewareId)) {
            return $middleware;
        }

        array_unshift($middleware, ['id' => $middlewareId]);

        return $middleware;
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function hasMiddleware(array $middleware, string $middlewareId): bool
    {
        foreach ($middleware as $middlewareItem) {
            if (!is_array($middlewareItem)) {
                continue;
            }

            if (($middlewareItem['id'] ?? null) === $middlewareId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Automatically replaces the default bundle metrics service alias with a user-provided custom implementation.
     *
     * During extension load each metrics interface (e.g. HttpServerMetricsInterface) gets aliased to the
     * corresponding default service (e.g. 'danilovl.open_telemetry.metrics.http_server.default').
     * If the developer registers their own service that implements the same interface, this method detects it
     * and re-points the alias to the custom service — without requiring any explicit alias configuration.
     * If more than one custom implementation is found, a LogicException is thrown to force an explicit choice.
     */
    private function overrideInterfaceAliasesByUserImplementations(ContainerBuilder $container): void
    {
        foreach ($this->getInterfaceDefaultServiceMap() as $interface => $defaultServiceId) {
            if (!$container->hasAlias($interface)) {
                continue;
            }

            $currentAlias = (string) $container->getAlias($interface);

            // Only proceed if the alias still points to the bundle default.
            // If the developer already set a custom alias, we must not override it.
            if ($currentAlias !== $defaultServiceId) {
                continue;
            }

            $defaultClass = $this->resolveDefinitionClass($container, $defaultServiceId);

            $serviceIds = $this->findCustomImplementations(
                container: $container,
                interface: $interface,
                defaultServiceId: $defaultServiceId,
                defaultClass: $defaultClass,
            );

            if ($serviceIds === []) {
                continue;
            }

            if (count($serviceIds) > 1) {
                $message = sprintf(
                    'Multiple services implement "%s" (%s). Please configure explicit alias for the interface.',
                    $interface,
                    implode(', ', $serviceIds)
                );

                throw new LogicException($message);
            }

            $container->setAlias($interface, $serviceIds[0])->setPublic(false);
        }
    }

    /**
     * @return array<string, string>
     */
    private function getInterfaceDefaultServiceMap(): array
    {
        return [
            HttpServerMetricsInterface::class => 'danilovl.open_telemetry.metrics.http_server.default',
            MessengerMetricsInterface::class => 'danilovl.open_telemetry.metrics.messenger.default',
            AsyncMetricsInterface::class => 'danilovl.open_telemetry.metrics.async.default',
            HttpClientMetricsInterface::class => 'danilovl.open_telemetry.metrics.http_client.default',
            RedisMetricsInterface::class => 'danilovl.open_telemetry.metrics.redis.default',
            CacheMetricsInterface::class => 'danilovl.open_telemetry.metrics.cache.default',
            ConsoleMetricsInterface::class => 'danilovl.open_telemetry.metrics.console.default',
            MailerMetricsInterface::class => 'danilovl.open_telemetry.metrics.mailer.default',
            EventDispatcherMetricsInterface::class => 'danilovl.open_telemetry.metrics.events.default',
            TraceableMetricsInterface::class => 'danilovl.open_telemetry.metrics.traceable.default',
            DoctrineMetricsInterface::class => 'danilovl.open_telemetry.metrics.doctrine.default',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function findCustomImplementations(
        ContainerBuilder $container,
        string $interface,
        string $defaultServiceId,
        ?string $defaultClass,
    ): array {
        $serviceIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($serviceId === $defaultServiceId) {
                continue;
            }

            $class = $definition->getClass();

            if (!is_string($class) || $class === '') {
                continue;
            }

            $resolvedClass = $container->getParameterBag()->resolveValue($class);

            if (!is_string($resolvedClass) || $resolvedClass === '' || !class_exists($resolvedClass)) {
                continue;
            }

            if ($defaultClass !== null && $resolvedClass === $defaultClass) {
                continue;
            }

            if (is_a($resolvedClass, $interface, true)) {
                $serviceIds[] = $serviceId;
            }
        }

        return $serviceIds;
    }

    /**
     * Resolves the concrete class name for a container service definition.
     *
     * A definition may not have an explicit class when it is created via a factory method
     * (e.g. TracerProviderInterface::class is produced by DefaultTracerProviderFactory::create()).
     * In that case we use Reflection to inspect the factory method's return type declaration
     * and derive the actual class from it — allowing the Redis/Predis decorator logic
     * to correctly identify factory-produced services.
     */
    private function resolveDefinitionClass(ContainerBuilder $container, string $serviceId): ?string
    {
        if (!$container->hasDefinition($serviceId)) {
            return null;
        }

        $definition = $container->getDefinition($serviceId);
        $class = $definition->getClass();

        if ($class !== null) {
            $class = $container->getParameterBag()->resolveValue($class);
        }

        // If no explicit class is set on the definition, attempt to derive it
        // from the return type of the factory method via Reflection.
        if ($class === null || $class === '') {
            $factory = $definition->getFactory();
            if (is_array($factory) && isset($factory[0])) {
                $factoryClass = null;

                if ($factory[0] instanceof Reference) {
                    // Factory is another container service — resolve it recursively.
                    $factoryClass = $this->resolveDefinitionClass($container, (string) $factory[0]);
                } elseif (is_string($factory[0])) {
                    $factoryClass = $container->getParameterBag()->resolveValue($factory[0]);
                }

                if (is_string($factoryClass) && class_exists($factoryClass)) {
                    $method = $factory[1];

                    try {
                        $reflectionMethod = new ReflectionMethod($factoryClass, $method);
                        $returnType = $reflectionMethod->getReturnType();
                        if ($returnType instanceof ReflectionNamedType) {
                            $class = $returnType->getName();
                        }
                    } catch (ReflectionException) {
                        // ignore
                    }
                }
            }
        }

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return null;
        }

        return $class;
    }
}
