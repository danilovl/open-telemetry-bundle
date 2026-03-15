<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\{
    DoctrineMetricsInterface,
    DoctrineSpanNameHandlerInterface,
    DoctrineTraceIgnoreInterface
};
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class TracingDbalMiddleware implements Middleware
{
    /**
     * @param DoctrineSpanNameHandlerInterface[] $doctrineSpanNameHandlers
     * @param DoctrineTraceIgnoreInterface[] $doctrineTraceIgnores
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::DOCTRINE_SPAN_NAME_HANDLER)]
        private readonly iterable $doctrineSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::DOCTRINE_TRACE_IGNORE)]
        private readonly iterable $doctrineTraceIgnores = [],
        private readonly ?DoctrineMetricsInterface $doctrineMetrics = null,
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

    public function wrap(Driver $driver): Driver
    {
        return new TraceableDriver(
            instrumentation: $this->instrumentation,
            driver: $driver,
            doctrineSpanNameHandlers: $this->doctrineSpanNameHandlers,
            doctrineTraceIgnores: $this->doctrineTraceIgnores,
            doctrineMetrics: $this->doctrineMetrics,
        );
    }
}
