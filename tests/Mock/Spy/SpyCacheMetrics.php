<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Throwable;

final class SpyCacheMetrics implements CacheMetricsInterface
{
    /** @var list<array{key: string, hit: bool}> */
    public array $gets = [];

    /** @var list<array{key: string, exception: Throwable}> */
    public array $errors = [];

    public function recordGet(string $key, bool $hit, float $durationMs): void
    {
        $this->gets[] = ['key' => $key, 'hit' => $hit];
    }

    public function recordError(string $key, Throwable $exception): void
    {
        $this->errors[] = ['key' => $key, 'exception' => $exception];
    }
}
