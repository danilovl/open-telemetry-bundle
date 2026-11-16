<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry;

use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\{
    Context,
    ScopeInterface
};
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface
};
use OpenTelemetry\SDK\Metrics\Data\{
    Gauge,
    Histogram,
    Sum
};
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\{
    ImmutableSpan,
    SpanDataInterface,
    TracerProvider
};
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use RuntimeException;

/** Real SDK with in-memory exporters, registered as Globals. */
final class InMemoryTelemetry
{
    public readonly InMemoryExporter $spanExporter;

    public readonly TracerProvider $tracerProvider;

    public readonly InMemoryMetricExporter $metricExporter;

    public readonly ExportingReader $metricReader;

    public readonly MeterProviderInterface $meterProvider;

    private ?ScopeInterface $scope = null;

    public function __construct()
    {
        $this->spanExporter = new InMemoryExporter;
        $spanProcessor = new SimpleSpanProcessor($this->spanExporter);
        $this->tracerProvider = new TracerProvider($spanProcessor);

        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = MeterProvider::builder()->addReader($this->metricReader)->build();
    }

    public static function install(): self
    {
        $telemetry = new self;
        $telemetry->scope = Configurator::create()
            ->withTracerProvider($telemetry->tracerProvider)
            ->withMeterProvider($telemetry->meterProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->storeInContext()
            ->activate();

        return $telemetry;
    }

    /** Fails when an instrumentation leaked a context scope. */
    public function uninstall(): void
    {
        if ($this->scope === null) {
            return;
        }

        $leaked = 0;
        while (($current = Context::storage()->scope()) !== null && $current !== $this->scope) {
            $current->detach();
            $leaked++;
        }

        $this->scope->detach();
        $this->scope = null;

        if ($leaked > 0) {
            throw new RuntimeException(sprintf('Instrumentation leaked %d context scope(s) (attach() without detach()).', $leaked));
        }
    }

    /**
     * @return list<ImmutableSpan>
     */
    public function spans(): array
    {
        /** @var list<ImmutableSpan> $spans */
        $spans = array_values($this->spanExporter->getSpans());

        return $spans;
    }

    public function span(string $name): ImmutableSpan
    {
        $found = $this->findSpan($name);

        if ($found === null) {
            $names = array_map(static fn (SpanDataInterface $span): string => $span->getName(), $this->spans());

            throw new RuntimeException(sprintf('Span "%s" not found. Recorded: [%s]', $name, implode(', ', $names)));
        }

        return $found;
    }

    public function findSpan(string $name): ?ImmutableSpan
    {
        return array_find($this->spans(), static fn (ImmutableSpan $span): bool => $span->getName() === $name);
    }

    /**
     * @return list<string>
     */
    public function spanNames(): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), $this->spans());
    }

    public function reset(): void
    {
        $this->spanExporter->getStorage()->exchangeArray([]);
    }

    /**
     * @return array<string, Metric>
     */
    public function collectMetrics(): array
    {
        $this->metricReader->collect();
        $metrics = [];

        foreach ($this->metricExporter->collect(true) as $metric) {
            $metrics[$metric->name] = $metric;
        }

        return $metrics;
    }

    public static function metricCount(Metric $metric): int|float
    {
        $total = 0;
        $data = $metric->data;

        if ($data instanceof Histogram) {
            foreach ($data->dataPoints as $dataPoint) {
                $total += $dataPoint->count;
            }
        } elseif ($data instanceof Sum || $data instanceof Gauge) {
            foreach ($data->dataPoints as $dataPoint) {
                $total += $dataPoint->value;
            }
        }

        return $total;
    }
}
