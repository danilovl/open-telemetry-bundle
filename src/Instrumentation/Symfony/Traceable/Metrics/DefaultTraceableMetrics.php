<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultTraceableMetrics implements TraceableMetricsInterface
{
    private bool $isEnable;

    private MetricsRecorderInterface $metricsRecorder;

    public function __construct(
        bool $isEnable,
        MetricsRecorderInterface $metricsRecorder,
    ) {
        $this->isEnable = $isEnable;
        $this->metricsRecorder = $metricsRecorder;
    }

    public function recordServiceMethod(string $className, string $methodName, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'traceable.class' => $className,
            'traceable.method' => $methodName,
        ];

        $this->metricsRecorder->addCounter(
            name: 'traceable.service_method.requests_total',
            attributes: $attributes,
            unit: '{call}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'traceable.service_method.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'traceable.service_method.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordServiceMethodError(string $className, string $methodName, Throwable $throwable): void
    {
        if (!$this->isEnable) {
            return;
        }

        $this->metricsRecorder->addCounter(
            name: 'traceable.service_method.errors_total',
            attributes: [
                'traceable.class' => $className,
                'traceable.method' => $methodName,
                'error.type' => $throwable::class,
            ],
            unit: '{error}'
        );
    }
}
