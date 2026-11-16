<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk\{
    InMemoryMeterProviderFactory,
    InMemoryTracerProviderFactory,
    SdkTestKernel
};
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Metrics\Data\{
    Gauge,
    Histogram,
    HistogramDataPoint,
    NumberDataPoint,
    Sum
};
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class HttpMetricsAndTracingFlagsTest extends TestCase
{
    private static SdkTestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new SdkTestKernel([
            'instrumentation' => [
                'http_server' => [
                    'tracing' => ['enabled' => false],
                    'metering' => ['enabled' => true],
                ],
            ],
        ]);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    private function handle(string $uri): void
    {
        $request = Request::create($uri);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
    }

    /**
     * The flush at kernel.terminate already exports, so points are spread over exports.
     *
     * @return array<string, list<HistogramDataPoint|NumberDataPoint>>
     */
    private function collectDataPoints(): array
    {
        /** @var InMemoryMeterProviderFactory $factory */
        $factory = self::$kernel->getContainer()->get(DefaultMeterProviderFactory::class);
        $factory->reader->collect();

        $points = [];
        foreach ($factory->exporter->collect(true) as $metric) {
            $data = $metric->data;
            $this->assertTrue($data instanceof Histogram || $data instanceof Sum || $data instanceof Gauge);
            $this->assertTrue($metric->name !== 'http.server.request.duration' || $data instanceof Histogram);

            foreach ($data->dataPoints as $point) {
                $points[$metric->name][] = $point;
            }
        }

        return $points;
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function spans(): array
    {
        /** @var InMemoryTracerProviderFactory $factory */
        $factory = self::$kernel->getContainer()->get(DefaultTracerProviderFactory::class);

        /** @var list<ImmutableSpan> $spans */
        $spans = array_values($factory->exporter->getSpans());

        return $spans;
    }

    public function testServerMetricsOnceWithoutTracing(): void
    {
        $this->collectDataPoints();

        $this->handle('/users/1');
        $this->handle('/users/2');
        $this->handle('/cache');

        $points = $this->collectDataPoints();
        $this->assertArrayHasKey('http.server.request.duration', $points);

        $counts = [];
        foreach ($points['http.server.request.duration'] as $point) {
            $this->assertInstanceOf(HistogramDataPoint::class, $point);
            $route = $point->attributes->get('http.route');
            $this->assertIsString($route);
            $counts[$route] = ($counts[$route] ?? 0) + $point->count;
        }

        $this->assertEquals(['/users/{id}' => 2, '/cache' => 1], $counts);

        $total = 0;
        foreach ($points['http.server.requests_total'] as $point) {
            $this->assertInstanceOf(NumberDataPoint::class, $point);
            $total += $point->value;
        }
        $this->assertSame(3, $total);

        $kinds = array_map(static fn (ImmutableSpan $span): int => $span->getKind(), $this->spans());
        $this->assertNotContains(SpanKind::KIND_SERVER, $kinds);
        $this->assertNotEmpty(array_filter($this->spans(), static fn (ImmutableSpan $s): bool => $s->getName() === 'cache get'));
    }
}
