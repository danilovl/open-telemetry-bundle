<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use OpenTelemetry\API\Metrics\{
    ObservableCounterInterface,
    ObservableGaugeInterface,
    ObservableUpDownCounterInterface,
    ObservableCallbackInterface,
    AsynchronousInstrument
};

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
