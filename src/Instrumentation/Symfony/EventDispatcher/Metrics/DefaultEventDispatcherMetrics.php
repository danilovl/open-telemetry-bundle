<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventDispatcherMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultEventDispatcherMetrics implements EventDispatcherMetricsInterface
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

    public function recordDispatch(object $event, ?string $eventName, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'event.class' => $event::class,
            'event.name' => $this->resolveEventName($eventName),
        ];

        $this->metricsRecorder->addCounter(
            name: 'event.dispatch.requests_total',
            attributes: $attributes,
            unit: '{event}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'event.dispatch.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'event.dispatch.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(object $event, ?string $eventName, Throwable $throwable, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'event.class' => $event::class,
            'event.name' => $this->resolveEventName($eventName),
            'error.type' => $throwable::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'event.dispatch.requests_total',
            attributes: $attributes,
            unit: '{event}'
        );

        $this->metricsRecorder->addCounter(
            name: 'event.dispatch.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'event.dispatch.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'event.dispatch.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    private function resolveEventName(?string $eventName): string
    {
        if (is_string($eventName) && $eventName !== '') {
            return $eventName;
        }

        return 'unknown';
    }
}
