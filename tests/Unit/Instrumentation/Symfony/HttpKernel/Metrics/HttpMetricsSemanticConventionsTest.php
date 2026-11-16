<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\HttpKernel\Metrics;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Metrics\DefaultCacheMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Metrics\DefaultHttpClientMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Metrics\DefaultHttpServerMetrics;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\MetricsRecorder;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\{
    InMemoryTelemetry,
    InMemoryTelemetryTrait
};
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\SDK\Metrics\Data\{
    Gauge,
    Histogram,
    Metric,
    Sum
};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class HttpMetricsSemanticConventionsTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private function recorder(): MetricsRecorder
    {
        $instrumentation = new CachedInstrumentation('danilovl/open-telemetry');

        return new MetricsRecorder('danilovl/open-telemetry', $instrumentation);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstPointAttributes(Metric $metric): array
    {
        $data = $metric->data;
        $this->assertTrue($data instanceof Histogram || $data instanceof Sum || $data instanceof Gauge);

        $points = iterator_to_array($data->dataPoints);

        return $points[0]->attributes->toArray();
    }

    public function testServerDurationSemconv(): void
    {
        $request = Request::create('/users/42?token=x');
        $request->attributes->set('_route', 'user_show');
        $request->attributes->set(HttpRequestTracingSubscriber::ROUTE_TEMPLATE_ATTRIBUTE, '/users/{id}');

        new DefaultHttpServerMetrics(true, $this->recorder())->recordRequest($request, 200, 1_500.0);

        $metrics = $this->telemetry->collectMetrics();
        $this->assertArrayHasKey('http.server.request.duration', $metrics);

        $duration = $metrics['http.server.request.duration'];
        $this->assertSame('s', $duration->unit);
        $this->assertInstanceOf(Histogram::class, $duration->data);

        $points = iterator_to_array($duration->data->dataPoints);
        $this->assertEqualsWithDelta(1.5, $points[0]->sum, 0.000_1, 'Duration must be in seconds');

        $attributes = $this->firstPointAttributes($duration);
        $this->assertSame('GET', $attributes['http.request.method']);
        $this->assertSame(200, $attributes['http.response.status_code']);
        $this->assertSame('/users/{id}', $attributes['http.route'], 'http.route must be the template, not the raw path');
        $this->assertArrayNotHasKey('error.type', $attributes);
    }

    public function testUnmatchedRouteHasNoRoute(): void
    {
        new DefaultHttpServerMetrics(true, $this->recorder())->recordRequest(Request::create('/random/404/path'), 404, 1.0);

        $attributes = $this->firstPointAttributes($this->telemetry->collectMetrics()['http.server.request.duration']);
        $this->assertArrayNotHasKey('http.route', $attributes, 'Raw paths must never become metric attributes');
    }

    public function testHttpServerErrorStatusHasErrorType(): void
    {
        new DefaultHttpServerMetrics(true, $this->recorder())->recordRequest(Request::create('/'), 503, 1.0);

        $attributes = $this->firstPointAttributes($this->telemetry->collectMetrics()['http.server.request.duration']);
        $this->assertSame('503', $attributes['error.type']);
    }

    public function testDisabledMetricsRecordNothing(): void
    {
        new DefaultHttpServerMetrics(false, $this->recorder())->recordRequest(Request::create('/'), 200, 1.0);

        $this->assertSame([], $this->telemetry->collectMetrics());
    }

    public function testClientDurationSemconv(): void
    {
        $metrics = new DefaultHttpClientMetrics(true, $this->recorder());
        $metrics->recordRequest('POST', 'https://api.example.com/v1/orders?api_key=secret', [], ['http_code' => 201], 250.0);

        $duration = $this->telemetry->collectMetrics()['http.client.request.duration'];
        $attributes = $this->firstPointAttributes($duration);

        $this->assertSame('s', $duration->unit);
        $this->assertSame('POST', $attributes['http.request.method']);
        $this->assertSame('api.example.com', $attributes['server.address']);
        $this->assertSame(201, $attributes['http.response.status_code']);
        $this->assertNotContains('secret', array_map(strval(...), array_filter($attributes, is_scalar(...))));
    }

    public function testHttpClientErrorMetric(): void
    {
        new DefaultHttpClientMetrics(true, $this->recorder())
            ->recordError('GET', 'https://down.example.com/', [], new RuntimeException('timeout'), 10.0);

        $metrics = $this->telemetry->collectMetrics();
        $this->assertSame(1, InMemoryTelemetry::metricCount($metrics['http.client.errors_total']));
        $this->assertSame(RuntimeException::class, $this->firstPointAttributes($metrics['http.client.errors_total'])['error.type']);
    }

    public function testCacheMetricsDoNotUseKeyAsAttribute(): void
    {
        $metrics = new DefaultCacheMetrics(true, $this->recorder());

        for ($i = 0; $i < 50; $i++) {
            $metrics->recordGet('user_' . $i, $i % 2 === 0, 1.0);
        }

        $requests = $this->telemetry->collectMetrics()['cache.requests_total']->data;
        $this->assertInstanceOf(Sum::class, $requests);
        $points = iterator_to_array($requests->dataPoints);

        $this->assertCount(2, $points);
        $this->assertArrayNotHasKey('cache.key', $points[0]->attributes->toArray());
    }
}
