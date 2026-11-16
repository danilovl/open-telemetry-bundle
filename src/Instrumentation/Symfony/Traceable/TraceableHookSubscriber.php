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
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\{
    HookRegistry,
    ScopedInstrumentation
};
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

final class TraceableHookSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.traceable';

    private bool $initialized = false;

    private static ?self $active = null;

    /**
     * Open frames per hooked method; null when the call is not traced.
     *
     * @var array<string, list<array{scope: ContextStorageScopeInterface, span: SpanInterface, start_time: int|float, class: string, method: string}|null>>
     */
    private static array $stacks = [];

    /**
     * @var array<string, array{class: string, method: string, name: string|null, attributes: array<string, mixed>}>
     */
    private array $hookDefinitions = [];

    /**
     * @param array<int, array{class: string, method: string, name: string|null, attributes: array<string, mixed>}> $hooks
     * @param iterable<TraceableAttributeProviderInterface> $attributeProviders
     * @param TraceableMetricsInterface|null $traceableMetrics
     * @param iterable<TraceableSpanNameHandlerInterface> $traceableSpanNameHandlers
     * @param iterable<TraceableTraceIgnoreInterface> $traceableTraceIgnores
     */
    public function __construct(
        private readonly ScopedInstrumentation $instrumentation,
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

        $this->initialized = true;

        if (!HookRegistry::isAvailable()) {
            return;
        }

        // hooks are process-wide, the current container's subscriber handles them
        self::$active = $this;

        foreach ($this->hooks as $hookDefinition) {
            $key = $hookDefinition['class'] . '::' . $hookDefinition['method'];
            $this->hookDefinitions[$key] = $hookDefinition;

            HookRegistry::hook(
                owner: self::class,
                class: $hookDefinition['class'],
                method: $hookDefinition['method'],
                pre: static function (mixed ...$args) use ($key): void {
                    self::$active?->onHookPre($key, $args);
                },
                post: static function (mixed ...$args) use ($key): void {
                    self::$active?->onHookPost($key, $args);
                }
            );
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function onHookPre(string $key, array $args): void
    {
        $hookDefinition = $this->hookDefinitions[$key] ?? null;

        // a frame is pushed in every case, so post always pops its own pre
        if ($hookDefinition === null) {
            self::$stacks[$key][] = null;

            return;
        }

        $className = $hookDefinition['class'];
        $methodName = $hookDefinition['method'];
        $traceableName = $hookDefinition['name'];
        $traceableAttributes = $hookDefinition['attributes'];

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

        foreach ($this->traceableSpanNameHandlers as $traceableSpanNameHandler) {
            $spanName = $traceableSpanNameHandler->process($spanName, $context);
        }

        foreach ($this->traceableTraceIgnores as $traceableTraceIgnore) {
            if ($traceableTraceIgnore->shouldIgnore($spanName, $context)) {
                self::$stacks[$key][] = null;

                return;
            }
        }

        $startTime = hrtime(true);

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'traceable.unknown' : $spanName;

        $span = $this->instrumentation
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
            providers: $this->attributeProviders,
            context: [
                'class' => $className,
                'method' => $methodName,
                'arguments' => $args,
            ]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));

        self::$stacks[$key][] = [
            'scope' => $scope,
            'span' => $span,
            'start_time' => $startTime,
            'class' => $className,
            'method' => $methodName,
        ];
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function onHookPost(string $key, array $args): void
    {
        $frame = array_pop(self::$stacks[$key]);

        if ($frame === null) {
            return;
        }

        $span = $frame['span'];
        $throwable = null;

        foreach ($args as $argument) {
            if ($argument instanceof Throwable) {
                $throwable = $argument;
                $span->recordException($argument);
                $span->setStatus(StatusCode::STATUS_ERROR, $argument->getMessage());

                break;
            }
        }

        $frame['scope']->detach();
        $span->end();

        $durationMs = (hrtime(true) - $frame['start_time']) / 1_000_000;
        $this->traceableMetrics?->recordServiceMethod($frame['class'], $frame['method'], $durationMs);

        if ($throwable instanceof Throwable) {
            $this->traceableMetrics?->recordServiceMethodError($frame['class'], $frame['method'], $throwable);
        }
    }

    /** @internal tests only */
    public function getOpenSpanCount(): int
    {
        return array_sum(array_map(
            static fn (array $frames): int => count(array_filter($frames, static fn (?array $frame): bool => $frame !== null)),
            self::$stacks
        ));
    }
}
