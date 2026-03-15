<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\DependencyInjection;

use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces\AsyncMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventDispatcherMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\MessageBusTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
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
        $this->overrideInterfaceAliasesByUserImplementations($container);
        $this->registerTracerProviderProcessors($container);
    }

    private function registerTracerProviderProcessors(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TracerProviderInterface::class)) {
            return;
        }

        $definition = $container->getDefinition(TracerProviderInterface::class);
        $processors = [];
        foreach ($container->findTaggedServiceIds(InstrumentationTags::SPAN_PROCESSOR) as $id => $tags) {
            $processors[] = new Reference($id);
        }

        if (count($processors) > 0) {
            $definition->setArgument('$processors', $processors);
        }
    }

    private function registerMessengerMiddleware(ContainerBuilder $container): void
    {
        $middlewareIds = [
            self::MESSAGE_BUS_MIDDLEWARE
        ];

        if ($container->hasParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER)) {
            $middleware = $container->getParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER);

            if (is_array($middleware)) {
                foreach ($middlewareIds as $middlewareId) {
                    $middleware = $this->appendMiddleware($container, $middleware, $middlewareId);
                }

                $container->setParameter(self::DEFAULT_MESSENGER_BUS_MIDDLEWARE_PARAMETER, $middleware);
            }
        }

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

        $templateDefinition = $container->getDefinition($templateId);

        foreach ($container->findTaggedServiceIds('http_client') as $id => $tags) {
            if ($id === 'http_client' || $id === $templateId) {
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

    private function overrideInterfaceAliasesByUserImplementations(ContainerBuilder $container): void
    {
        foreach ($this->getInterfaceDefaultServiceMap() as $interface => $defaultServiceId) {
            if (!$container->hasAlias($interface)) {
                continue;
            }

            $currentAlias = (string) $container->getAlias($interface);

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

    private function resolveDefinitionClass(ContainerBuilder $container, string $serviceId): ?string
    {
        if (!$container->hasDefinition($serviceId)) {
            return null;
        }

        $class = $container->getDefinition($serviceId)->getClass();

        if (!is_string($class) || $class === '') {
            return null;
        }

        $resolvedClass = $container->getParameterBag()->resolveValue($class);

        if (!is_string($resolvedClass) || $resolvedClass === '' || !class_exists($resolvedClass)) {
            return null;
        }

        return $resolvedClass;
    }
}
