<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces;

use Throwable;

interface RedisMetricsInterface
{
    public function recordCommand(string $command, float $durationMs): void;

    public function recordError(string $command, Throwable $exception, float $durationMs): void;
}
