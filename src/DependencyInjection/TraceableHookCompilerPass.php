<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\DependencyInjection;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\TraceableHookSubscriber;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TraceableHookCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, array{class: string, method: string, name: string|null, attributes: array<string, mixed>}> $hooks */
        $hooks = [];

        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }

            $className = $definition->getClass();

            if (!is_string($className) || $className === '' || str_contains($className, '%') || !class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            $classTraceable = $this->resolveTraceableFromClass($reflection);

            if (!$classTraceable instanceof Traceable && !$this->hasTraceableMethod($reflection)) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isAbstract() || $method->isConstructor() || $method->isDestructor()) {
                    continue;
                }

                $methodName = $method->getName();
                if (str_starts_with($methodName, '__') && $methodName !== '__invoke') {
                    continue;
                }

                $methodTraceable = $this->resolveTraceableFromMethod($method);
                $traceable = $methodTraceable ?? $classTraceable;

                if (!$traceable instanceof Traceable) {
                    continue;
                }

                if ($traceable->handler !== TraceableHandler::HOOK) {
                    continue;
                }

                $hooks[$className . '::' . $methodName] = [
                    'class' => $className,
                    'method' => $methodName,
                    'name' => $traceable->name,
                    'attributes' => $traceable->attributes,
                ];
            }
        }

        $traceableHookSubscriberId = TraceableHookSubscriber::class;
        if (!$container->hasDefinition($traceableHookSubscriberId)) {
            return;
        }

        $container
            ->getDefinition($traceableHookSubscriberId)
            ->setArgument('$hooks', array_values($hooks));
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function hasTraceableMethod(ReflectionClass $reflection): bool
    {
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->resolveTraceableFromMethod($method) instanceof Traceable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveTraceableFromClass(ReflectionClass $reflection): ?Traceable
    {
        $attributes = $reflection->getAttributes(Traceable::class);
        $attribute = isset($attributes[0]) ? $attributes[0]->newInstance() : null;

        return $attribute instanceof Traceable ? $attribute : null;
    }

    private function resolveTraceableFromMethod(ReflectionMethod $method): ?Traceable
    {
        $attributes = $method->getAttributes(Traceable::class);
        $attribute = isset($attributes[0]) ? $attributes[0]->newInstance() : null;

        return $attribute instanceof Traceable ? $attribute : null;
    }
}
