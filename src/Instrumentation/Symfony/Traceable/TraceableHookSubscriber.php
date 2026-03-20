<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\{
    TraceableAttributeProviderInterface,
    TraceableMetricsInterface,
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
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

final class TraceableHookSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.traceable';

    private bool $initialized = false;

    /**
     * @param array<int, array{class: string, method: string, name: string|null, attributes: array<string, mixed>}> $hooks
     * @param iterable<TraceableAttributeProviderInterface> $attributeProviders
     * @param TraceableMetricsInterface|null $traceableMetrics
     * @param iterable<TraceableSpanNameHandlerInterface> $traceableSpanNameHandlers
     * @param iterable<TraceableTraceIgnoreInterface> $traceableTraceIgnores
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::TRACEABLE_ATTRIBUTE_PROVIDER)]
        private readonly iterable $attributeProviders = [],
        private readonly array $hooks = [],
        private readonly ?TraceableMetricsInterface $traceableMetrics = null,
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
            KernelEvents::REQUEST => ['initializeHooks', 2_048],
            ConsoleEvents::COMMAND => ['initializeHooksForConsole', 2_048]
        ];
    }

    public function initializeHooks(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->initialize();
    }

    public function initializeHooksForConsole(ConsoleCommandEvent $event): void
    {
        $this->initialize();
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!function_exists('OpenTelemetry\\Instrumentation\\hook')) {
            $this->initialized = true;

            return;
        }

        $this->initialized = true;

        foreach ($this->hooks as $hookDefinition) {
            $className = $hookDefinition['class'];
            $methodName = $hookDefinition['method'];

            /** @var array<int, array{scope: ContextStorageScopeInterface|null, span: SpanInterface|null, start_time: int|null}> $stack */
            $stack = [];
            $traceableName = $hookDefinition['name'];
            $traceableAttributes = $hookDefinition['attributes'];
            $attributeProviders = $this->attributeProviders;
            $traceableMetrics = $this->traceableMetrics;
            $traceableSpanNameHandlers = $this->traceableSpanNameHandlers;
            $traceableTraceIgnores = $this->traceableTraceIgnores;
            $instrumentation = $this->instrumentation;

            hook(
                class: $className,
                function: $methodName,
                pre: static function (...$args) use (&$stack, $traceableName, $traceableAttributes, $attributeProviders, $className, $methodName, $traceableSpanNameHandlers, $traceableTraceIgnores, $instrumentation): void {
                    $spanName = is_string($traceableName) && $traceableName !== ''
                        ? $traceableName
                        : sprintf('traceable %s::%s', $className, $methodName);

                    $context = [
                        'operation' => 'service_method',
                        'class' => $className,
                        'method' => $methodName,
                        'arguments' => $args,
                        'traceable_name' => $traceableName,
                        'traceable_attributes' => $traceableAttributes,
                    ];

                    foreach ($traceableSpanNameHandlers as $traceableSpanNameHandler) {
                        $spanName = $traceableSpanNameHandler->process($spanName, $context);
                    }

                    foreach ($traceableTraceIgnores as $traceableTraceIgnore) {
                        if ($traceableTraceIgnore->shouldIgnore($spanName, $context)) {
                            $stack[] = ['scope' => null, 'span' => null, 'start_time' => null];

                            return;
                        }
                    }

                    $startTime = hrtime(true);

                    /** @var non-empty-string $spanNameNonEmpty */
                    $spanNameNonEmpty = $spanName === '' ? 'traceable.unknown' : $spanName;

                    $span = $instrumentation
                        ->tracer()
                        ->spanBuilder($spanNameNonEmpty)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setAttribute('transaction.type', 'service_method')
                        ->setAttribute('traceable.type', 'service_method')
                        ->setAttribute('traceable.class', $className)
                        ->setAttribute('traceable.method', $methodName)
                        ->startSpan();

                    $span->setAttributes(TracingHelper::normalizeAttributeValues($traceableAttributes));

                    SpanAttributeEnricher::enrich(
                        span: $span,
                        providers: $attributeProviders,
                        context: [
                            'class' => $className,
                            'method' => $methodName,
                            'arguments' => $args,
                        ]
                    );

                    $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                    $stack[] = [
                        'scope' => $scope,
                        'span' => $span,
                        'start_time' => $startTime,
                    ];
                },
                post: static function (...$args) use (&$stack, $className, $methodName, $traceableMetrics): void {
                    $hookContext = array_pop($stack);

                    if (!is_array($hookContext)) {
                        return;
                    }

                    $scope = $hookContext['scope'] ?? null;
                    $span = $hookContext['span'] ?? null;
                    $startTime = $hookContext['start_time'] ?? null;

                    if (!$span instanceof SpanInterface) {
                        return;
                    }

                    $throwable = null;
                    foreach ($args as $argument) {
                        if ($argument instanceof Throwable) {
                            $throwable = $argument;
                            $span->recordException($argument);
                            $span->setStatus(StatusCode::STATUS_ERROR, $argument->getMessage());

                            break;
                        }
                    }

                    if ($scope instanceof ContextStorageScopeInterface) {
                        $scope->detach();
                    }

                    $span->end();

                    if (is_numeric($startTime)) {
                        $durationMs = (hrtime(true) - $startTime) / 1_000_000;
                        $traceableMetrics?->recordServiceMethod($className, $methodName, $durationMs);

                        if ($throwable instanceof Throwable) {
                            $traceableMetrics?->recordServiceMethodError($className, $methodName, $throwable);
                        }
                    }
                }
            );
        }
    }
}
