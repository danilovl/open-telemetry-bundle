<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces;

use Throwable;

interface TraceableMetricsInterface
{
    public function recordServiceMethod(string $className, string $methodName, float $durationMs): void;

    public function recordServiceMethodError(string $className, string $methodName, Throwable $throwable): void;
}
