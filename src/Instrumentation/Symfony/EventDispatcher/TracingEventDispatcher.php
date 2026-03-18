<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\{
    EventAttributeProviderInterface,
    EventDispatcherMetricsInterface,
    EventSpanNameHandlerInterface,
    EventTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SpanAttributeEnricher;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    Span,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use Symfony\Component\DependencyInjection\Attribute\{
    AsDecorator,
    AutowireDecorated,
    AutowireIterator
};
use Symfony\Component\EventDispatcher\{
    EventDispatcherInterface,
    EventSubscriberInterface
};
use Throwable;

#[AsDecorator(decorates: 'event_dispatcher')]
final readonly class TracingEventDispatcher implements EventDispatcherInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.events';

    /**
     * @param EventDispatcherInterface $inner
     * @param iterable<EventAttributeProviderInterface> $eventAttributeProviders
     * @param iterable<EventSpanNameHandlerInterface> $eventSpanNameHandlers
     * @param iterable<EventTraceIgnoreInterface> $eventTraceIgnores
     * @param EventDispatcherMetricsInterface|null $eventDispatcherMetrics
     */
    public function __construct(
        #[AutowireDecorated]
        private EventDispatcherInterface $inner,
        private CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::EVENT_ATTRIBUTE_PROVIDER)]
        private iterable $eventAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::EVENT_SPAN_NAME_HANDLER)]
        private iterable $eventSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::EVENT_TRACE_IGNORE)]
        private iterable $eventTraceIgnores = [],
        private ?EventDispatcherMetricsInterface $eventDispatcherMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$eventAttributeProviders',
            items: $this->eventAttributeProviders,
            expectedType: EventAttributeProviderInterface::class);

        AutowireIteratorTypeValidator::validate(
            argumentName: '$eventSpanNameHandlers',
            items: $this->eventSpanNameHandlers,
            expectedType: EventSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$eventTraceIgnores',
            items: $this->eventTraceIgnores,
            expectedType: EventTraceIgnoreInterface::class
        );
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $parentContext = Context::getCurrent();
        $parentSpan = Span::fromContext($parentContext);

        if (!$parentSpan->getContext()->isValid()) {
            return $this->inner->dispatch($event, $eventName);
        }

        $spanName = 'event dispatch';
        $startTime = hrtime(true);

        foreach ($this->eventSpanNameHandlers as $eventSpanNameHandler) {
            $spanName = $eventSpanNameHandler->process($spanName, $event, $eventName);
        }

        foreach ($this->eventTraceIgnores as $eventTraceIgnore) {
            if ($eventTraceIgnore->shouldIgnore($spanName, $event, $eventName)) {
                return $this->inner->dispatch($event, $eventName);
            }
        }

        $spanNameNonEmpty = $spanName === '' ? 'unknown' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('transaction.type', 'event')
            ->setAttribute('event.class', $event::class)
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->eventAttributeProviders,
            context: ['event' => $event, 'eventName' => $eventName]
        );

        $scope = Context::storage()->attach($span->storeInContext($parentContext));

        try {
            $result = $this->inner->dispatch($event, $eventName);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->eventDispatcherMetrics?->recordDispatch($event, $eventName, $durationMs);

            return $result;
        } catch (Throwable $throwable) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->eventDispatcherMetrics?->recordError($event, $eventName, $throwable, $durationMs);

            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());

            throw $throwable;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function addListener(string $eventName, mixed $listener, int $priority = 0): void
    {
        /** @var callable $listener */
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    public function removeListener(string $eventName, mixed $listener): void
    {
        /** @var callable $listener */
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    /**
     * @return array<string, array<int, callable>>|array<int, callable>
     */
    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    public function getListenerPriority(string $eventName, mixed $listener): ?int
    {
        /** @var callable $listener */
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }
}
