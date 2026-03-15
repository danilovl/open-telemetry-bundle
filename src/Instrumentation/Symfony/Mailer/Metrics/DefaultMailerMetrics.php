<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultMailerMetrics implements MailerMetricsInterface
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

    public function recordSent(object $message, string $transport, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'email.class' => $message::class,
            'email.transport' => $transport,
        ];

        $this->metricsRecorder->addCounter(
            name: 'mailer.message.requests_total',
            attributes: $attributes,
            unit: '{message}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'mailer.message.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'mailer.message.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordFailed(object $message, string $transport, Throwable $error, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'email.class' => $message::class,
            'email.transport' => $transport,
            'error.type' => $error::class,
        ];

        $this->metricsRecorder->addCounter(
            name: 'mailer.message.requests_total',
            attributes: $attributes,
            unit: '{message}'
        );

        $this->metricsRecorder->addCounter(
            name: 'mailer.message.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'mailer.message.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'mailer.message.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
