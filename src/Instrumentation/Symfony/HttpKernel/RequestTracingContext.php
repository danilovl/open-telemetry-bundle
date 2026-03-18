<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;

final class RequestTracingContext
{
    private bool $finished = false;

    public function __construct(
        public readonly SpanInterface $span,
        public readonly ContextStorageScopeInterface $scope,
        public readonly int|float $startTime,
    ) {}

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->scope->detach();
        $this->span->end();
    }

    public function __destruct()
    {
        $this->finish();
    }
}
