<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Service;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Metrics\{
    CounterInterface,
    HistogramInterface,
    GaugeInterface,
    UpDownCounterInterface,
    ObservableCounterInterface,
    ObservableGaugeInterface,
    ObservableUpDownCounterInterface,
    ObservableCallbackInterface,
    AsynchronousInstrument
};

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\MetricsRecorderInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\TracingHelper;

final class MetricsRecorder implements MetricsRecorderInterface
{
    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, UpDownCounterInterface> */
    private array $upDownCounters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    /** @var array<string, GaugeInterface> */
    private array $gauges = [];

    /** @var array<string, ObservableCounterInterface> */
    private array $observableCounters = [];

    /** @var array<string, ObservableGaugeInterface> */
    private array $observableGauges = [];

    /** @var array<string, ObservableUpDownCounterInterface> */
    private array $observableUpDownCounters = [];

    public function __construct(
        private readonly string $meterName,
        private readonly CachedInstrumentation $instrumentation,
    ) {}

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addCounter(
        string $name,
        float|int $amount = 1,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void {
        if ($this->meterName === '') {
            return;
        }

        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        $counter = $this->counters[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createCounter($name, $unit, $description);

        $counter->add($amount, TracingHelper::normalizeAttributeValues($attributes));
    }

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addUpDownCounter(
        string $name,
        float|int $amount = 1,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void {
        if ($this->meterName === '') {
            return;
        }

        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        $upDownCounter = $this->upDownCounters[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createUpDownCounter($name, $unit, $description);

        $upDownCounter->add($amount, TracingHelper::normalizeAttributeValues($attributes));
    }

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function recordHistogram(
        string $name,
        float|int $amount,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void {
        if ($this->meterName === '') {
            return;
        }

        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        $histogram = $this->histograms[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createHistogram($name, $unit, $description);

        $histogram->record($amount, TracingHelper::normalizeAttributeValues($attributes));
    }

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function recordGauge(
        string $name,
        float|int $amount,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void {
        if ($this->meterName === '') {
            return;
        }

        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        $gauge = $this->gauges[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createGauge($name, $unit, $description);

        $gauge->record($amount, TracingHelper::normalizeAttributeValues($attributes));
    }

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableCounterInterface {
        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        return $this->observableCounters[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createObservableCounter($name, $unit, $description, $advisory, ...$callbacks);
    }

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableGaugeInterface {
        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        return $this->observableGauges[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createObservableGauge($name, $unit, $description, $advisory, ...$callbacks);
    }

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableUpDownCounterInterface {
        $key = $name . '|' . ($unit ?? '') . '|' . ($description ?? '');

        return $this->observableUpDownCounters[$key] ??= $this->getInstrumentation()
            ->meter()
            ->createObservableUpDownCounter($name, $unit, $description, $advisory, ...$callbacks);
    }

    public function batchObserve(
        callable $callback,
        AsynchronousInstrument $instrument,
        AsynchronousInstrument ...$instruments,
    ): ObservableCallbackInterface {
        return $this->getInstrumentation()
            ->meter()
            ->batchObserve($callback, $instrument, ...$instruments);
    }

    private function getInstrumentation(): CachedInstrumentation
    {
        return $this->instrumentation;
    }
}
