<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
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
            'http.request.method' => $request->getMethod(),
            'http.response.status_code' => $statusCode,
            'url.scheme' => $request->getScheme(),
            ...$this->resolveRouteAttributes($request),
        ];

        if ($statusCode >= 500) {
            $attributes['error.type'] = (string) $statusCode;
        }

        $this->metricsRecorder->addCounter(
            name: 'http.server.requests_total',
            attributes: $attributes,
            unit: '{request}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'http.server.request.duration',
            amount: $durationMs / 1_000,
            attributes: $attributes,
            unit: 's',
            description: 'Duration of HTTP server requests.'
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
            'http.request.method' => $request->getMethod(),
            'error.type' => $exception::class,
            ...$this->resolveRouteAttributes($request),
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

    /**
     * @return array<string, string>
     */
    private function resolveRouteAttributes(Request $request): array
    {
        $template = $request->attributes->get(HttpRequestTracingSubscriber::ROUTE_TEMPLATE_ATTRIBUTE);

        if (is_string($template) && $template !== '') {
            return ['http.route' => $template];
        }

        return [];
    }
}
