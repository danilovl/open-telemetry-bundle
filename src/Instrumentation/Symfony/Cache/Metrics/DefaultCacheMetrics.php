<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultCacheMetrics implements CacheMetricsInterface
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

    public function recordGet(string $key, bool $hit, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'cache.operation' => 'get',
            'cache.key' => $key,
            'cache.hit' => $hit,
        ];

        $this->metricsRecorder->addCounter('cache.requests_total', 1, $attributes, '{operation}');
        $this->metricsRecorder->recordHistogram('cache.duration_ms', $durationMs, $attributes, 'ms');

        if ($hit) {
            $this->metricsRecorder->addCounter('cache.hits_total', 1, $attributes, '{item}');
        } else {
            $this->metricsRecorder->addCounter('cache.misses_total', 1, $attributes, '{item}');
        }

        $this->metricsRecorder->recordGauge(
            name: 'cache.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(string $key, Throwable $exception): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'cache.operation' => 'get',
            'cache.key' => $key,
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'cache.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordGauge(
            name: 'cache.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
