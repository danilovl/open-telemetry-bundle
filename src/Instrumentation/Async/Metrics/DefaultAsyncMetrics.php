<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Async\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Async\Interfaces\AsyncMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;

final readonly class DefaultAsyncMetrics implements AsyncMetricsInterface
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

    public function recordCall(float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = ['async.operation' => 'call'];

        $this->metricsRecorder->addCounter('async.requests_total', 1, $attributes, '{call}');
        $this->metricsRecorder->recordHistogram('async.duration_ms', $durationMs, $attributes, 'ms');

        $this->metricsRecorder->recordGauge(
            name: 'async.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
