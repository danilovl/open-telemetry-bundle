<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultDoctrineMetrics implements DoctrineMetricsInterface
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

    public function recordCall(string $dbSystem, string $operation, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            DoctrineContextAttribute::SYSTEM->value => $dbSystem,
            DoctrineContextAttribute::OPERATION->value => $operation,
        ];

        $this->metricsRecorder->addCounter('db.client.requests_total', 1, $attributes, '{call}');
        $this->metricsRecorder->recordHistogram('db.client.duration_ms', $durationMs, $attributes, 'ms');

        $this->metricsRecorder->recordGauge(
            name: 'db.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(string $dbSystem, string $operation, Throwable $exception, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            DoctrineContextAttribute::SYSTEM->value => $dbSystem,
            DoctrineContextAttribute::OPERATION->value => $operation,
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter('db.client.requests_total', 1, $attributes, '{call}');
        $this->metricsRecorder->addCounter('db.client.errors_total', 1, $attributes, '{error}');
        $this->metricsRecorder->recordHistogram('db.client.duration_ms', $durationMs, $attributes, 'ms');

        $this->metricsRecorder->recordGauge(
            name: 'db.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
