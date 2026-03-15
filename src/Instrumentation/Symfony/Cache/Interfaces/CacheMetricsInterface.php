<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces;

use Throwable;

interface CacheMetricsInterface
{
    public function recordGet(string $key, bool $hit, float $durationMs): void;

    public function recordError(string $key, Throwable $exception): void;
}
