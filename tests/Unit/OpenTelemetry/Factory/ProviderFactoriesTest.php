<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Factory;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\DefaultLoggerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator\EnvPropagatorFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource\DefaultResourceInfoFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\{
    NoopTracerProvider,
    TracerProvider
};
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use PHPUnit\Framework\TestCase;

class ProviderFactoriesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $this->originalEnv[$name] = getenv($name);
        }

        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_SERVER[$name]);
            } else {
                putenv($name . '=' . $value);
                $_SERVER[$name] = $value;
            }
        }

        $this->originalEnv = [];
    }

    private function resourceFactory(): DefaultResourceInfoFactory
    {
        return new DefaultResourceInfoFactory(['namespace' => null, 'name' => 'test-app', 'version' => null, 'environment' => 'test']);
    }

    private function tracerFactory(): DefaultTracerProviderFactory
    {
        $resourceFactory = $this->resourceFactory();

        return new DefaultTracerProviderFactory($resourceFactory);
    }

    private function meterFactory(): DefaultMeterProviderFactory
    {
        $resourceFactory = $this->resourceFactory();

        return new DefaultMeterProviderFactory($resourceFactory);
    }

    private function loggerFactory(): DefaultLoggerProviderFactory
    {
        $resourceFactory = $this->resourceFactory();

        return new DefaultLoggerProviderFactory($resourceFactory);
    }

    public function testSdkDisabledReturnsNoopProviders(): void
    {
        $this->setEnv('OTEL_SDK_DISABLED', 'true');

        $tracerProvider = $this->tracerFactory()->create();
        $meterProvider = $this->meterFactory()->create();
        $loggerProvider = $this->loggerFactory()->create();

        $this->assertInstanceOf(NoopTracerProvider::class, $tracerProvider);
        $this->assertInstanceOf(NoopMeterProvider::class, $meterProvider);
        $this->assertInstanceOf(NoopLoggerProvider::class, $loggerProvider);
    }

    public function testExporterNoneKeepsCustomProcessors(): void
    {
        $this->setEnv('OTEL_TRACES_EXPORTER', 'none');
        $exporter = new InMemoryExporter;

        $processor = new SimpleSpanProcessor($exporter);
        $provider = $this->tracerFactory()->create([$processor]);
        $this->assertInstanceOf(TracerProvider::class, $provider);

        $provider->getTracer('t')->spanBuilder('span')->startSpan()->end();

        $this->assertCount(1, $exporter->getSpans());
        $this->assertSame('test-app', $exporter->getSpans()[0]->getResource()->getAttributes()->get('service.name'));
    }

    public function testSamplerIsReadFromEnvironment(): void
    {
        $this->setEnv('OTEL_TRACES_EXPORTER', 'none');
        $this->setEnv('OTEL_TRACES_SAMPLER', 'always_off');
        $exporter = new InMemoryExporter;

        $processor = new SimpleSpanProcessor($exporter);
        $provider = $this->tracerFactory()->create([$processor]);
        $span = $provider->getTracer('t')->spanBuilder('span')->startSpan();
        $span->end();

        $this->assertFalse($span->isRecording());
        $this->assertCount(0, $exporter->getSpans());
    }

    public function testTraceIdRatioSampler(): void
    {
        $this->setEnv('OTEL_TRACES_EXPORTER', 'none');
        $this->setEnv('OTEL_TRACES_SAMPLER', 'traceidratio');
        $this->setEnv('OTEL_TRACES_SAMPLER_ARG', '0');
        $exporter = new InMemoryExporter;

        $processor = new SimpleSpanProcessor($exporter);
        $provider = $this->tracerFactory()->create([$processor]);
        for ($i = 0; $i < 20; $i++) {
            $provider->getTracer('t')->spanBuilder('span')->startSpan()->end();
        }

        $this->assertCount(0, $exporter->getSpans());
    }

    public function testInvalidExporterIsIgnored(): void
    {
        $this->setEnv('OTEL_TRACES_EXPORTER', 'does-not-exist');
        $this->setEnv('OTEL_METRICS_EXPORTER', 'does-not-exist');
        $this->setEnv('OTEL_LOGS_EXPORTER', 'none');
        $this->setEnv('OTEL_PHP_LOG_DESTINATION', 'none');

        $tracerProvider = $this->tracerFactory()->create();
        $meterProvider = $this->meterFactory()->create();
        $loggerProvider = $this->loggerFactory()->create();

        $this->assertInstanceOf(TracerProvider::class, $tracerProvider);
        $this->assertNotInstanceOf(NoopMeterProvider::class, $meterProvider);
        $this->assertNotInstanceOf(NoopLoggerProvider::class, $loggerProvider);
    }

    public function testConsoleExportersCanBeSelected(): void
    {
        $this->setEnv('OTEL_TRACES_EXPORTER', 'console');
        $this->setEnv('OTEL_METRICS_EXPORTER', 'none');
        $this->setEnv('OTEL_LOGS_EXPORTER', 'console');

        $tracerProvider = new DefaultTracerProviderFactory($this->resourceFactory())->create();
        $meterProvider = new DefaultMeterProviderFactory($this->resourceFactory())->create();
        $loggerProvider = new DefaultLoggerProviderFactory($this->resourceFactory())->create();

        $this->assertInstanceOf(TracerProvider::class, $tracerProvider);
        $this->assertTrue($tracerProvider->shutdown());
        $this->assertTrue($meterProvider->shutdown());
        $this->assertTrue($loggerProvider->shutdown());
    }

    public function testPropagatorDefaults(): void
    {
        $propagator = new EnvPropagatorFactory()->create();

        $this->assertInstanceOf(MultiTextMapPropagator::class, $propagator);
        $this->assertContains('traceparent', $propagator->fields());
        $this->assertContains('baggage', $propagator->fields());
    }

    public function testPropagatorIsReadFromEnvironment(): void
    {
        $this->setEnv('OTEL_PROPAGATORS', 'tracecontext');
        $this->assertSame(TraceContextPropagator::getInstance(), new EnvPropagatorFactory()->create());

        $this->setEnv('OTEL_PROPAGATORS', 'baggage');
        $this->assertSame(BaggagePropagator::getInstance(), new EnvPropagatorFactory()->create());
    }
}
