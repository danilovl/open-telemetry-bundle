<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class DefaultHttpServerMetrics implements HttpServerMetricsInterface
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

    public function recordRequest(Request $request, int $statusCode, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'http.method' => $request->getMethod(),
            'http.route' => $this->resolveRoute($request),
            'http.status_code' => $statusCode,
        ];

        $this->metricsRecorder->addCounter(
            name: 'http.server.requests_total',
            attributes: $attributes,
            unit: '{request}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'http.server.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'http.server.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(Request $request, Throwable $exception): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'http.method' => $request->getMethod(),
            'http.route' => $this->resolveRoute($request),
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'http.server.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordGauge(
            name: 'http.server.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    private function resolveRoute(Request $request): string
    {
        $route = $request->attributes->get('_route');

        if (is_string($route) && $route !== '') {
            return $route;
        }

        return 'unknown';
    }
}
