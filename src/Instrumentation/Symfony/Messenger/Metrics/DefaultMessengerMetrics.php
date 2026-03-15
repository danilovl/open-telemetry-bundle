<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultMessengerMetrics implements MessengerMetricsInterface
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

    /**
     * @param array<string, mixed> $messagingAttributes
     */
    public function recordMessage(object $message, string $operation, array $messagingAttributes, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'messaging.message.type' => $message::class,
            'messaging.operation' => $operation,
            ...$messagingAttributes,
        ];

        $this->metricsRecorder->addCounter(
            name: 'messenger.message.requests_total',
            attributes: $attributes,
            unit: '{message}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'messenger.message.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'messenger.message.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    /**
     * @param array<string, mixed> $messagingAttributes
     */
    public function recordError(object $message, string $operation, array $messagingAttributes, Throwable $exception, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'messaging.message.type' => $message::class,
            'messaging.operation' => $operation,
            ...$messagingAttributes,
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'messenger.message.requests_total',
            attributes: $attributes,
            unit: '{message}'
        );

        $this->metricsRecorder->addCounter(
            name: 'messenger.message.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'messenger.message.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'messenger.message.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
