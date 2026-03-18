<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Async;

use Danilovl\AsyncBundle\Event\{
    AsyncPostCallEvent,
    AsyncPreCallEvent
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces\AsyncMetricsInterface;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind
};
use OpenTelemetry\Context\{
    Context,
    ContextStorageScopeInterface
};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AsyncTracingSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.async';

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

    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        private readonly ?AsyncMetricsInterface $asyncMetrics = null,
    ) {}

    /**
     * @return array<class-string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AsyncPreCallEvent::class => ['onAsyncPre', 1_000],
            AsyncPostCallEvent::class => ['onAsyncPost', -1_000],
        ];
    }

    public function onAsyncPre(): void
    {
        $parent = Context::getCurrent();

        $spanName = 'async call';

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('transaction.type', 'async')
            ->setAttribute('async.system', 'async')
            ->setParent($parent)
            ->startSpan();

        $context = $span->storeInContext($parent);
        $scope = Context::storage()->attach($context);

        $this->spans[] = $span;
        $this->scopes[] = $scope;
        $this->startTimes[] = hrtime(true);
    }

    public function onAsyncPost(): void
    {
        $scope = array_pop($this->scopes);
        $span = array_pop($this->spans);
        $startTime = array_pop($this->startTimes);

        if ($scope instanceof ContextStorageScopeInterface) {
            $scope->detach();
        }

        if ($span instanceof SpanInterface) {
            $span->end();
        }

        if (is_numeric($startTime)) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;
            $this->asyncMetrics?->recordCall($durationMs);
        }
    }
}
