<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Service;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\SpanAttributes;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\TracingHelper;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracingSpanServiceInterface;
use OpenTelemetry\API\Trace\{
    Span,
    SpanKind,
    StatusCode,
    TracerProviderInterface
};
use OpenTelemetry\Context\ContextStorageInterface;
use Throwable;
use Error;

readonly class TracingSpanService implements TracingSpanServiceInterface
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private ContextStorageInterface $contextStorage
    ) {}

    public function start(string $name): TracingSpanServiceInterface
    {
        $parentContext = $this->contextStorage->current();

        $span = $this->tracerProvider
            ->getTracer(__CLASS__)
            ->spanBuilder($name === '' ? 'unknown' : $name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(SpanAttributes::RECORDED_LOCATION->value, $this->calledFrom())
            ->setParent($parentContext)
            ->startSpan();

        $this->contextStorage->attach($span->storeInContext($parentContext));

        return $this;
    }

    public function createNewCurrent(): TracingSpanServiceInterface
    {
        return $this;
    }

    public function end(): void
    {
        $span = Span::fromContext($this->contextStorage->current());
        $span->end();

        $scope = $this->contextStorage->scope();
        if ($scope !== null) {
            $scope->detach();
        }
    }

    public function setAttribute(string $key, mixed $value): TracingSpanServiceInterface
    {
        if ($key !== '') {
            $span = Span::fromContext($this->contextStorage->current());
            $span->setAttribute($key, TracingHelper::normalizeAttributeValue($value));
        }

        return $this;
    }

    public function setAttributes(array $attributes): TracingSpanServiceInterface
    {
        $span = Span::fromContext($this->contextStorage->current());
        $span->setAttributes(TracingHelper::normalizeAttributeValues($attributes));

        return $this;
    }

    public function addEvent(string $message, array $attributes = []): TracingSpanServiceInterface
    {
        $span = Span::fromContext($this->contextStorage->current());
        $span->addEvent($message, TracingHelper::normalizeAttributeValues($attributes));

        return $this;
    }

    public function addErrorEvent(string $message, array $attributes = []): TracingSpanServiceInterface
    {
        $this->recordHandledException(new Error($message), $attributes, $this->calledFrom());

        return $this;
    }

    public function recordHandledException(
        Throwable $exception,
        array $attributes = [],
        ?string $location = null
    ): TracingSpanServiceInterface {
        $span = Span::fromContext($this->contextStorage->current());

        $span->recordException($exception, [
            'exception.escaped' => false,
            ...TracingHelper::normalizeAttributeValues($attributes),
            ...TracingHelper::extractTracingAttributesFromObject($exception),
            SpanAttributes::RECORDED_LOCATION->value => $location ?? $this->calledFrom()
        ]);

        return $this;
    }

    public function markOutcomeAsFailure(?string $description = null): TracingSpanServiceInterface
    {
        $span = Span::fromContext($this->contextStorage->current());
        $span->setStatus(StatusCode::STATUS_ERROR, $description);

        return $this;
    }

    public function markOutcomeAsSuccess(?string $description = null): TracingSpanServiceInterface
    {
        $span = Span::fromContext($this->contextStorage->current());
        $span->setStatus(StatusCode::STATUS_OK, $description);

        return $this;
    }

    private function calledFrom(): string
    {
        $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        return sprintf('%s:%d', $stackTrace[1]['file'] ?? 'unknown', $stackTrace[1]['line'] ?? 'unknown');
    }
}
