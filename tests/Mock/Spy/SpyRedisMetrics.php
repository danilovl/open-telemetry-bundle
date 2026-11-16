<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Throwable;

final class SpyRedisMetrics implements RedisMetricsInterface
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $errors = [];

    public function recordCommand(string $command, float $durationMs): void
    {
        $this->commands[] = $command;
    }

    public function recordError(string $command, Throwable $exception, float $durationMs): void
    {
        $this->errors[] = $command;
    }
}
