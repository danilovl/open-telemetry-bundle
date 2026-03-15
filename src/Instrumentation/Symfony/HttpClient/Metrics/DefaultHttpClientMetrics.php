<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultHttpClientMetrics implements HttpClientMetricsInterface
{
    private bool $isEnable;

    private MetricsRecorderInterface $metricsRecorder;

    public function __construct(
        bool $isEnable,
        MetricsRecorderInterface $metricsRecorder
    ) {
        $this->isEnable = $isEnable;
        $this->metricsRecorder = $metricsRecorder;
    }

    public function recordRequest(string $method, string $url, array $options, array $info, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $statusCode = 0;
        if (isset($info['http_code']) && is_scalar($info['http_code'])) {
            $statusCode = (int) $info['http_code'];
        }

        $attributes = [
            'http.method' => $method,
            'http.host' => (string) parse_url($url, PHP_URL_HOST),
            'http.status_code' => $statusCode,
        ];

        $this->metricsRecorder->addCounter(
            name: 'http.client.requests_total',
            attributes: $attributes,
            unit: '{request}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'http.client.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'http.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(string $method, string $url, array $options, Throwable $exception, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'http.method' => $method,
            'http.host' => (string) parse_url($url, PHP_URL_HOST),
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'http.client.requests_total',
            attributes: $attributes,
            unit: '{request}'
        );

        $this->metricsRecorder->addCounter(
            name: 'http.client.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'http.client.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'http.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
