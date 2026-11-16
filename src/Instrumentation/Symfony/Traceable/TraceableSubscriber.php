<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\{
    TraceableAttributeProviderInterface,
    TraceableSpanNameHandlerInterface,
    TraceableTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\{
    SpanAttributeEnricher,
    TracingHelper
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\{
    Context,
    ContextStorageScopeInterface
};
use Closure;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\{
    ConsoleCommandEvent,
    ConsoleErrorEvent,
    ConsoleTerminateEvent
};
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\{
    ControllerEvent,
    ExceptionEvent,
    FinishRequestEvent,
    TerminateEvent
};
use Symfony\Component\HttpKernel\KernelEvents;
use WeakMap;

final class TraceableSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.traceable';

    /**
     * @var WeakMap<Request, TraceableTracingContext>
     */
    private WeakMap $contextMap;

    /**
     * @var array<int, SpanInterface>
     */
    private array $commandSpans = [];

    /**
     * @var array<int, ContextStorageScopeInterface>
     */
    private array $commandScopes = [];

    /**
     * @param iterable<TraceableAttributeProviderInterface> $attributeProviders
     * @param iterable<TraceableSpanNameHandlerInterface> $traceableSpanNameHandlers
     * @param iterable<TraceableTraceIgnoreInterface> $traceableTraceIgnores
     */
    public function __construct(
        private readonly ScopedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::TRACEABLE_ATTRIBUTE_PROVIDER)]
        private readonly iterable $attributeProviders = [],
        #[AutowireIterator(InstrumentationTags::TRACEABLE_SPAN_NAME_HANDLER)]
        private readonly iterable $traceableSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::TRACEABLE_TRACE_IGNORE)]
        private readonly iterable $traceableTraceIgnores = [],
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$attributeProviders',
            items: $this->attributeProviders,
            expectedType: TraceableAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$traceableSpanNameHandlers',
            items: $this->traceableSpanNameHandlers,
            expectedType: TraceableSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$traceableTraceIgnores',
            items: $this->traceableTraceIgnores,
            expectedType: TraceableTraceIgnoreInterface::class
        );

        $this->contextMap = new WeakMap;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 50],
            KernelEvents::EXCEPTION => ['onHttpException', 10],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', -50],
            KernelEvents::TERMINATE => ['onHttpTerminate', -50],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 50],
            ConsoleEvents::ERROR => ['onConsoleError', 0],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -50]
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $traceable = $this->resolveControllerTraceable($controller);

        if (!$traceable instanceof Traceable) {
            return;
        }

        if ($traceable->handler !== TraceableHandler::CONTROLLER) {
            return;
        }

        $spanName = $traceable->name ?? 'traceable.controller';
        $context = [
            'operation' => 'controller',
            'event' => $event,
            'controller' => $controller,
            'traceable' => $traceable,
        ];

        foreach ($this->traceableSpanNameHandlers as $traceableSpanNameHandler) {
            $spanName = $traceableSpanNameHandler->process($spanName, $context);
        }

        foreach ($this->traceableTraceIgnores as $traceableTraceIgnore) {
            if ($traceableTraceIgnore->shouldIgnore($spanName, $context)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'traceable.controller' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('transaction.type', 'request')
            ->setAttribute('traceable.type', 'controller')
            ->startSpan();

        $span->setAttributes(TracingHelper::normalizeAttributeValues($traceable->attributes));

        SpanAttributeEnricher::enrich(
            $span,
            $this->attributeProviders,
            ['event' => $event, 'controller' => $controller]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        $request = $event->getRequest();

        $this->contextMap[$request] = new TraceableTracingContext(span: $span, scope: $scope);
    }

    public function onHttpException(ExceptionEvent $event): void
    {
        $span = ($this->contextMap[$event->getRequest()] ?? null)?->span;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $throwable = $event->getThrowable();
        $span->recordException($throwable);
        $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
    }

    /** Also covers sub-requests, which have no kernel.terminate. */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();
        $context = $this->contextMap[$request] ?? null;

        if (!$context instanceof TraceableTracingContext) {
            return;
        }

        $context->finish();
        unset($this->contextMap[$request]);
    }

    public function onHttpTerminate(TerminateEvent $event): void
    {
        $context = $this->contextMap[$event->getRequest()] ?? null;

        if (!$context instanceof TraceableTracingContext) {
            return;
        }

        $context->finish();
        unset($this->contextMap[$event->getRequest()]);
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command === null) {
            return;
        }

        $reflection = new ReflectionClass($command);
        $traceable = $this->resolveClassTraceable($reflection);

        if (!$traceable instanceof Traceable) {
            return;
        }

        if ($traceable->handler !== TraceableHandler::COMMAND) {
            return;
        }

        $spanName = $traceable->name ?? 'traceable.console';
        $context = [
            'operation' => 'console_command',
            'event' => $event,
            'command' => $command,
            'traceable' => $traceable,
        ];

        foreach ($this->traceableSpanNameHandlers as $traceableSpanNameHandler) {
            $spanName = $traceableSpanNameHandler->process($spanName, $context);
        }

        foreach ($this->traceableTraceIgnores as $traceableTraceIgnore) {
            if ($traceableTraceIgnore->shouldIgnore($spanName, $context)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'traceable.console' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('transaction.type', 'cli')
            ->setAttribute('traceable.type', 'console_command')
            ->setAttribute('traceable.class', $command::class)
            ->startSpan();

        $span->setAttributes(TracingHelper::normalizeAttributeValues($traceable->attributes));

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->attributeProviders,
            context: ['event' => $event, 'command' => $command]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        $key = spl_object_id($event->getInput());

        $this->commandSpans[$key] = $span;
        $this->commandScopes[$key] = $scope;
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $key = spl_object_id($event->getInput());
        $span = $this->commandSpans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $error = $event->getError();
        $span->recordException($error);
        $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $key = spl_object_id($event->getInput());
        $span = $this->commandSpans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $scope = $this->commandScopes[$key] ?? null;
        if ($scope instanceof ContextStorageScopeInterface) {
            $scope->detach();
        }

        $span->setAttribute('traceable.exit_code', $event->getExitCode());
        $span->end();

        unset($this->commandSpans[$key], $this->commandScopes[$key]);
    }

    /** Method attribute wins over class attribute, same as TraceableHookCompilerPass. */
    private function resolveControllerTraceable(mixed $controller): ?Traceable
    {
        if (is_array($controller) && isset($controller[0], $controller[1]) && is_object($controller[0]) && is_string($controller[1])) {
            [$object, $methodName] = $controller;
        } elseif (is_object($controller) && !$controller instanceof Closure) {
            // invokable controller
            $object = $controller;
            $methodName = '__invoke';
        } else {
            return null;
        }

        $reflectionClass = new ReflectionClass($object);

        if ($reflectionClass->hasMethod($methodName)) {
            $methodTraceable = $this->resolveMethodTraceable($reflectionClass->getMethod($methodName));

            if ($methodTraceable instanceof Traceable) {
                return $methodTraceable;
            }
        }

        return $this->resolveClassTraceable($reflectionClass);
    }

    /**
     * @param ReflectionClass<*> $reflectionClass
     */
    private function resolveClassTraceable(ReflectionClass $reflectionClass): ?Traceable
    {
        $attributes = $reflectionClass->getAttributes(Traceable::class);
        $attribute = isset($attributes[0]) ? $attributes[0]->newInstance() : null;

        return $attribute instanceof Traceable ? $attribute : null;
    }

    private function resolveMethodTraceable(ReflectionMethod $reflectionMethod): ?Traceable
    {
        $attributes = $reflectionMethod->getAttributes(Traceable::class);
        $attribute = isset($attributes[0]) ? $attributes[0]->newInstance() : null;

        return $attribute instanceof Traceable ? $attribute : null;
    }
}
