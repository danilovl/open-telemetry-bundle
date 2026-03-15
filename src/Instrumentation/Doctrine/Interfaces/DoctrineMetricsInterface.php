<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces;

use Throwable;

interface DoctrineMetricsInterface
{
    public function recordCall(string $dbSystem, string $operation, float $durationMs): void;

    public function recordError(string $dbSystem, string $operation, Throwable $exception, float $durationMs): void;
}
