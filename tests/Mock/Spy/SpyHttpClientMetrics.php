<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientMetricsInterface;
use Throwable;

final class SpyHttpClientMetrics implements HttpClientMetricsInterface
{
    /** @var list<array{method: string, url: string, statusCode: mixed}> */
    public array $requests = [];

    /** @var list<array{method: string, url: string, exception: Throwable}> */
    public array $errors = [];

    public function recordRequest(string $method, string $url, array $options, array $info, float $durationMs): void
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'statusCode' => $info['http_code'] ?? null];
    }

    public function recordError(
        string $method,
        string $url,
        array $options,
        Throwable $exception,
        float $durationMs,
    ): void {
        $this->errors[] = ['method' => $method, 'url' => $url, 'exception' => $exception];
    }
}
