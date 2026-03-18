<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\API\Metrics\{
    ObservableCounterInterface,
    ObservableGaugeInterface,
    ObservableUpDownCounterInterface,
    ObservableCallbackInterface,
    AsynchronousInstrument
};

/**
 * Facade for recording application metrics via the OpenTelemetry Metrics API.
 *
 * Provides a unified entry point for all instrument types:
 * - Counters / UpDownCounters — for cumulative or bidirectional integer/float values.
 * - Histograms — for measuring distributions (e.g. request duration, payload size).
 * - Gauges — for recording the current value of a point-in-time measurement.
 * - Observable (async) instruments — for values that are measured on demand via callbacks.
 *
 * All synchronous methods accept optional $unit and $description which are used
 * to register the instrument on first use; subsequent calls with the same $name
 * reuse the already-registered instrument (instruments are cached by name).
 *
 * The concrete implementation is {@see \Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\MetricsRecorder},
 * which is registered as a singleton service in the DI container.
 */
interface MetricsRecorderInterface
{
    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addCounter(
        string $name,
        float|int $amount = 1,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addUpDownCounter(
        string $name,
        float|int $amount = 1,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function recordHistogram(
        string $name,
        float|int $amount,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function recordGauge(
        string $name,
        float|int $amount,
        array $attributes = [],
        ?string $unit = null,
        ?string $description = null,
    ): void;

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableCounterInterface;

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableGaugeInterface;

    /**
     * @param array<string, mixed>|callable $advisory
     */
    public function createObservableUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableUpDownCounterInterface;

    public function batchObserve(
        callable $callback,
        AsynchronousInstrument $instrument,
        AsynchronousInstrument ...$instruments,
    ): ObservableCallbackInterface;
}
