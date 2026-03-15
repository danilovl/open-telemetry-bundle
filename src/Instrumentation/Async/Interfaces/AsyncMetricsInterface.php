<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces;

interface AsyncMetricsInterface
{
    public function recordCall(float $durationMs): void;
}
