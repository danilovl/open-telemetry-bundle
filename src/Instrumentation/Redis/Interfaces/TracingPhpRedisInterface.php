<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces;

interface TracingPhpRedisInterface
{
    public function get(string $key): mixed;

    /**
     * @param int|array<string|int, mixed> $timeout
     */
    public function set(string $key, mixed $value, int|array $timeout = []): bool;

    public function setex(string $key, int $seconds, mixed $value): mixed;

    /**
     * @param string|array<int, string> $key
     */
    public function del(string|array $key, string ...$otherKeys): int;

    public function expire(string $key, int $seconds): bool;
}
