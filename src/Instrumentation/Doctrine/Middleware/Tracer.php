<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\TracingHelper;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode,
    TracerInterface
};
use OpenTelemetry\SemConv\Attributes\{
    DbAttributes,
    ErrorAttributes
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\{
    DoctrineMetricsInterface,
    DoctrineSpanNameHandlerInterface,
    DoctrineTraceIgnoreInterface
};
use OpenTelemetry\Context\Context;
use Throwable;

readonly class Tracer
{
    /**
     * @param iterable<DoctrineSpanNameHandlerInterface> $doctrineSpanNameHandlers
     * @param iterable<DoctrineTraceIgnoreInterface> $doctrineTraceIgnores
     * @param array<string, mixed> $defaultAttributes
     */
    public function __construct(
        private TracerInterface $tracer,
        private iterable $doctrineSpanNameHandlers = [],
        private iterable $doctrineTraceIgnores = [],
        private ?DoctrineMetricsInterface $doctrineMetrics = null,
        private array $defaultAttributes = [],
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$doctrineSpanNameHandlers',
            items: $this->doctrineSpanNameHandlers,
            expectedType: DoctrineSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$doctrineTraceIgnores',
            items: $this->doctrineTraceIgnores,
            expectedType: DoctrineTraceIgnoreInterface::class
        );
    }

    /**
     * @template T
     *
     * @param string $name
     * @param callable(SpanInterface|null): T $callback
     * @param array<string, mixed> $context
     *
     * @return T
     */
    public function traceFunction(string $name, callable $callback, array $context = [])
    {
        foreach ($this->doctrineSpanNameHandlers as $doctrineSpanNameHandler) {
            $name = $doctrineSpanNameHandler->process($name, $context);
        }

        foreach ($this->doctrineTraceIgnores as $doctrineTraceIgnore) {
            if ($doctrineTraceIgnore->shouldIgnore($name, $context)) {
                return $callback(null);
            }
        }

        $startTime = hrtime(true);

        $operation = is_string($context[DoctrineContextAttribute::OPERATION->value] ?? null) ? $context[DoctrineContextAttribute::OPERATION->value] : $name;

        $rawSystem = $context[DoctrineContextAttribute::SYSTEM->value] ?? $context[DbAttributes::DB_SYSTEM_NAME] ?? null;
        $dbSystem = is_string($rawSystem) ? $rawSystem : 'other_sql';

        $scope = Context::storage()->scope();
        $span = null;

        try {
            /** @var non-empty-string $spanNameBuilder */
            $spanNameBuilder = $name === '' ? 'db.operation' : $name;

            $attributes = TracingHelper::normalizeAttributeValues([
                DbAttributes::DB_SYSTEM_NAME => $dbSystem,
                DbAttributes::DB_OPERATION_NAME => $operation,
                ...$this->defaultAttributes,
            ]);

            $spanBuilder = $this->tracer
                ->spanBuilder($spanNameBuilder)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($scope?->context())
                ->setAttributes($attributes);

            $span = $spanBuilder->startSpan();

            $result = $callback($span);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->doctrineMetrics?->recordCall($dbSystem, $operation, $durationMs);

            return $result;
        } catch (Throwable $exception) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->doctrineMetrics?->recordError($dbSystem, $operation, $exception, $durationMs);

            if ($span instanceof SpanInterface) {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            throw $exception;
        } finally {
            if ($span instanceof SpanInterface) {
                $span->end();
            }
        }
    }
}
