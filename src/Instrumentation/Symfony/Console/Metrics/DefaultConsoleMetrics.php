<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleMetricsInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Symfony\Component\Console\Event\{
    ConsoleErrorEvent,
    ConsoleTerminateEvent
};

final readonly class DefaultConsoleMetrics implements ConsoleMetricsInterface
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

    public function recordError(ConsoleErrorEvent $event): void
    {
        if (!$this->isEnable) {
            return;
        }

        $this->metricsRecorder->addCounter(
            name: 'console.command.errors_total',
            attributes: [
                'console.command.name' => $event->getInput()->getFirstArgument() ?? 'console.command',
                'error.type' => $event->getError()::class,
            ],
            unit: '{error}'
        );
    }

    public function recordCommand(ConsoleTerminateEvent $event, string $commandName, float $durationMs): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'console.command.name' => $commandName,
            'console.command.exit_code' => $event->getExitCode(),
        ];

        $this->metricsRecorder->addCounter(
            name: 'console.command.requests_total',
            attributes: $attributes,
            unit: '{command}'
        );

        $this->metricsRecorder->recordHistogram(
            name: 'console.command.duration_ms',
            amount: $durationMs,
            attributes: $attributes,
            unit: 'ms'
        );

        $this->metricsRecorder->recordGauge(
            name: 'console.command.memory_usage',
            amount: (float) memory_get_usage(),
            attributes: $attributes,
            unit: 'By'
        );
    }

    public function recordExitError(ConsoleTerminateEvent $event, string $commandName): void
    {
        if (!$this->isEnable) {
            return;
        }

        $attributes = [
            'console.command.name' => $commandName,
            'console.command.exit_code' => $event->getExitCode(),
        ];

        $this->metricsRecorder->addCounter(
            name: 'console.command.errors_total',
            attributes: $attributes,
            unit: '{error}'
        );
    }
}
