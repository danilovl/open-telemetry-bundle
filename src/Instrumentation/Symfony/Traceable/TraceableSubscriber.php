<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\Traceable;
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
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\{
    Context,
    ContextStorageScopeInterface
};
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
use Symfony\Component\HttpKernel\Event\{
    ControllerEvent,
    ExceptionEvent,
    TerminateEvent
};
use Symfony\Component\HttpKernel\KernelEvents;

final class TraceableSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.traceable';
    private const string HTTP_SPAN_ATTRIBUTE = '_otel_traceable_span';
    private const string HTTP_SCOPE_ATTRIBUTE = '_otel_traceable_scope';

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
        private readonly CachedInstrumentation $instrumentation,
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
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 50],
            KernelEvents::EXCEPTION => ['onHttpException', 10],
            KernelEvents::TERMINATE => ['onHttpTerminate', -50],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 50],
            ConsoleEvents::ERROR => ['onConsoleError', 0],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -50],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $traceable = $this->resolveControllerTraceable($controller);

        if (!$traceable instanceof Traceable) {
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

        $request->attributes->set(self::HTTP_SPAN_ATTRIBUTE, $span);
        $request->attributes->set(self::HTTP_SCOPE_ATTRIBUTE, $scope);
    }

    public function onHttpException(ExceptionEvent $event): void
    {
        $span = $event->getRequest()->attributes->get(self::HTTP_SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $throwable = $event->getThrowable();
        $span->recordException($throwable);
        $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
    }

    public function onHttpTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $span = $request->attributes->get(self::HTTP_SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $scope = $request->attributes->get(self::HTTP_SCOPE_ATTRIBUTE);
        if ($scope instanceof ContextStorageScopeInterface) {
            $scope->detach();
        }

        $span->end();
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

    private function resolveControllerTraceable(mixed $controller): ?Traceable
    {
        if (is_array($controller) && isset($controller[0], $controller[1]) && is_object($controller[0]) && is_string($controller[1])) {
            $reflectionClass = new ReflectionClass($controller[0]);
            $classTraceable = $this->resolveClassTraceable($reflectionClass);

            if ($classTraceable instanceof Traceable) {
                return $classTraceable;
            }

            if ($reflectionClass->hasMethod($controller[1])) {
                return $this->resolveMethodTraceable($reflectionClass->getMethod($controller[1]));
            }
        }

        return null;
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
