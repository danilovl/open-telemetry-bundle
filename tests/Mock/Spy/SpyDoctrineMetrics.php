<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineMetricsInterface;
use Throwable;

final class SpyDoctrineMetrics implements DoctrineMetricsInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function recordCall(string $dbSystem, string $operation, float $durationMs): void
    {
        $this->calls[] = 'call:' . $operation;
    }

    public function recordError(string $dbSystem, string $operation, Throwable $exception, float $durationMs): void
    {
        $this->calls[] = 'error:' . $operation;
    }
}
