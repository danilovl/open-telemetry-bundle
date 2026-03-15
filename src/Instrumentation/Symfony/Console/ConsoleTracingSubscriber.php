<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\{
    ConsoleAttributeProviderInterface,
    ConsoleMetricsInterface,
    ConsoleSpanNameHandlerInterface,
    ConsoleTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SpanAttributeEnricher;
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
use Symfony\Component\Console\Event\{
    ConsoleCommandEvent,
    ConsoleErrorEvent,
    ConsoleTerminateEvent
};
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConsoleTracingSubscriber implements EventSubscriberInterface
{
    /**
     * @var array<int, SpanInterface>
     */
    private array $spans = [];

    /**
     * @var array<int, ContextStorageScopeInterface>
     */
    private array $scopes = [];

    /**
     * @var array<int, int|float>
     */
    private array $startTimes = [];

    /**
     * @var array<int, bool>
     */
    private array $hasErrors = [];

    /**
     * @param iterable<ConsoleAttributeProviderInterface> $attributeProviders
     * @param iterable<ConsoleSpanNameHandlerInterface> $consoleSpanNameHandlers
     * @param iterable<ConsoleTraceIgnoreInterface> $consoleTraceIgnores
     * @param ConsoleMetricsInterface|null $consoleMetrics
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::CONSOLE_ATTRIBUTE_PROVIDER)]
        private readonly iterable $attributeProviders = [],
        #[AutowireIterator(InstrumentationTags::CONSOLE_SPAN_NAME_HANDLER)]
        private readonly iterable $consoleSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::CONSOLE_TRACE_IGNORE)]
        private readonly iterable $consoleTraceIgnores = [],
        private readonly ?ConsoleMetricsInterface $consoleMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$attributeProviders',
            items: $this->attributeProviders,
            expectedType: ConsoleAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$consoleSpanNameHandlers',
            items: $this->consoleSpanNameHandlers,
            expectedType: ConsoleSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$consoleTraceIgnores',
            items: $this->consoleTraceIgnores,
            expectedType: ConsoleTraceIgnoreInterface::class
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 1_000],
            ConsoleEvents::ERROR => ['onError', 0],
            ConsoleEvents::TERMINATE => ['onTerminate', -1_000],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $name = $command?->getName() ?? $event->getInput()->getFirstArgument() ?? 'console.command';
        $spanName = 'console ' . $name;

        foreach ($this->consoleSpanNameHandlers as $consoleSpanNameHandler) {
            $spanName = $consoleSpanNameHandler->process($spanName, $event);
        }

        foreach ($this->consoleTraceIgnores as $consoleTraceIgnore) {
            if ($consoleTraceIgnore->shouldIgnore($spanName, $event)) {
                return;
            }
        }

        $spanNameNonEmpty = $spanName === '' ? 'unknown' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('transaction.type', 'cli')
            ->setAttribute('console.system', 'console')
            ->setAttribute('console.command', $name)
            ->setAttribute('console.command.name', $name)
            ->setAttribute('console.command.class', $command ? $command::class : 'unknown')
            ->startSpan();

        SpanAttributeEnricher::enrich(
            $span,
            $this->attributeProviders,
            ['event' => $event, 'command' => $command]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        $key = spl_object_id($event->getInput());

        $this->spans[$key] = $span;
        $this->scopes[$key] = $scope;
        $this->startTimes[$key] = hrtime(true);
        $this->hasErrors[$key] = false;
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $key = spl_object_id($event->getInput());
        $span = $this->spans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $span->recordException($event->getError());
        $span->setStatus(StatusCode::STATUS_ERROR, $event->getError()->getMessage());
        $this->hasErrors[$key] = true;

        $this->consoleMetrics?->recordError($event);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $key = spl_object_id($event->getInput());
        $span = $this->spans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $span->setAttribute('console.command.exit_code', $event->getExitCode());

        $scope = $this->scopes[$key] ?? null;

        if ($scope instanceof ContextStorageScopeInterface) {
            $scope->detach();
        }

        $command = $event->getCommand();
        $commandName = $command?->getName() ?? $event->getInput()->getFirstArgument() ?? 'console.command';
        $durationMs = isset($this->startTimes[$key]) ? (hrtime(true) - $this->startTimes[$key]) / 1_000_000 : 0;

        $this->consoleMetrics?->recordCommand($event, $commandName, $durationMs);

        if ($event->getExitCode() !== 0 && !($this->hasErrors[$key] ?? false)) {
            $this->consoleMetrics?->recordExitError($event, $commandName);
        }

        $span->end();

        unset($this->spans[$key], $this->scopes[$key], $this->startTimes[$key], $this->hasErrors[$key]);
    }
}
