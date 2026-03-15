<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces;

use Throwable;

interface HttpClientMetricsInterface
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $info
     */
    public function recordRequest(string $method, string $url, array $options, array $info, float $durationMs): void;

    /**
     * @param array<string, mixed> $options
     */
    public function recordError(string $method, string $url, array $options, Throwable $exception, float $durationMs): void;
}
