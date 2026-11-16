<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Service;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\EventSubscriber\TelemetryFlushSubscriber;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\TelemetryFlusher;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Metrics\{
    MeterProvider,
    MeterProviderInterface
};
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TelemetryFlusherTest extends TestCase
{
    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private InMemoryMetricExporter $metricExporter;

    private MeterProviderInterface $meterProvider;

    private ScopeInterface $scope;

    protected function setUp(): void
    {
        $this->spanExporter = new InMemoryExporter;
        // batch: nothing is exported until flush
        $spanProcessor = new BatchSpanProcessor($this->spanExporter, SystemClock::create());
        $this->tracerProvider = new TracerProvider($spanProcessor);
        $this->metricExporter = new InMemoryMetricExporter;
        $metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = MeterProvider::builder()->addReader($metricReader)->build();

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withMeterProvider($this->meterProvider)
            ->storeInContext()
            ->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    private function terminateEvent(): TerminateEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        $request = new Request;
        $response = new Response;

        return new TerminateEvent($kernel, $request, $response);
    }

    private function flushSubscriber(): TelemetryFlushSubscriber
    {
        $flusher = new TelemetryFlusher;

        return new TelemetryFlushSubscriber($flusher);
    }

    /**
     * @param non-empty-string $spanName
     */
    private function simulateRequest(string $spanName): void
    {
        $this->tracerProvider->getTracer('test')->spanBuilder($spanName)->startSpan()->end();
        $this->meterProvider->getMeter('test')->createCounter('requests')->add(1);
    }

    public function testEveryRequestExportedInWorker(): void
    {
        $subscriber = $this->flushSubscriber();

        $this->simulateRequest('request 1');
        $subscriber->onKernelTerminate($this->terminateEvent());
        $this->assertCount(1, $this->spanExporter->getSpans());

        $this->simulateRequest('request 2');
        $subscriber->onKernelTerminate($this->terminateEvent());

        $this->assertCount(2, $this->spanExporter->getSpans(), 'Telemetry of the 2nd request must not be lost');
        $this->assertSame('request 2', $this->spanExporter->getSpans()[1]->getName());
        $this->assertNotEmpty($this->metricExporter->collect(), 'Metrics must be flushed too');
    }

    public function testConsoleTerminateFlushes(): void
    {
        $this->simulateRequest('command');
        $this->flushSubscriber()->onConsoleTerminate();

        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function testFlushRunsLast(): void
    {
        $events = TelemetryFlushSubscriber::getSubscribedEvents();

        foreach ($events as $event) {
            $this->assertIsArray($event);
            $this->assertLessThan(-2_000, $event[1]);
        }
    }
}
