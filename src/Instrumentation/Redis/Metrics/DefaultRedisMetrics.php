<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Throwable;

final readonly class DefaultRedisMetrics implements RedisMetricsInterface
{
    private bool $isEnable;

    private MetricsRecorderInterface $metricsRecorder;

    private string $dbSystem;

    public function __construct(
        bool $isEnable,
        MetricsRecorderInterface $metricsRecorder,
        string $dbSystem = 'redis',
    ) {
        $this->isEnable = $isEnable;
        $this->metricsRecorder = $metricsRecorder;
        $this->dbSystem = $dbSystem;
    }

    public function recordCommand(string $command, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'db.system' => $this->dbSystem,
            'db.redis.command' => $command,
        ];

        $this->metricsRecorder->addCounter('redis.client.requests_total', 1, $attributes, '{command}');
        $this->metricsRecorder->recordHistogram('redis.client.duration_ms', $durationMs, $attributes, 'ms');

        $this->metricsRecorder->recordGauge(
            name: 'redis.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordError(string $command, Throwable $exception, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'db.system' => $this->dbSystem,
            'db.redis.command' => $command,
            'error.type' => $exception::class,
        ];

        $this->metricsRecorder->addCounter('redis.client.requests_total', 1, $attributes, '{command}');
        $this->metricsRecorder->addCounter('redis.client.errors_total', 1, $attributes, '{error}');
        $this->metricsRecorder->recordHistogram('redis.client.duration_ms', $durationMs, $attributes, 'ms');

        $this->metricsRecorder->recordGauge(
            name: 'redis.client.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }
}
