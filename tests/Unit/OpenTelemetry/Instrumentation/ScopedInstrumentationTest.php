<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Instrumentation;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\NoopTracer;
use PHPUnit\Framework\TestCase;

class ScopedInstrumentationTest extends TestCase
{
    use InMemoryTelemetryTrait;

    public function testEnabledTracingUsesGlobalProvider(): void
    {
        $instrumentation = ScopedInstrumentation::create('danilovl.test');
        $instrumentation->tracer()->spanBuilder('span')->startSpan()->end();

        $span = $this->telemetry->span('span');
        $this->assertSame('danilovl.test', $span->getInstrumentationScope()->getName());
        $this->assertTrue($instrumentation->isTracingEnabled());
    }

    public function testDisabledTracingUsesNoopTracer(): void
    {
        $instrumentation = ScopedInstrumentation::create('danilovl.test', false);

        $this->assertInstanceOf(NoopTracer::class, $instrumentation->tracer());
        $this->assertFalse($instrumentation->isTracingEnabled());
    }

    public function testDisabledTracingKeepsParentContext(): void
    {
        $enabled = ScopedInstrumentation::create('danilovl.enabled');
        $disabled = ScopedInstrumentation::create('danilovl.disabled', false);

        $parent = $enabled->tracer()->spanBuilder('parent')->startSpan();
        $parentScope = $parent->activate();

        $middle = $disabled->tracer()->spanBuilder('middle')->startSpan();
        $middleScope = $middle->activate();

        $child = $enabled->tracer()->spanBuilder('child')->startSpan();
        $child->end();

        $middleScope->detach();
        $middle->end();
        $parentScope->detach();
        $parent->end();

        $this->assertSame(['child', 'parent'], $this->telemetry->spanNames());
        $this->assertSame($parent->getContext()->getSpanId(), $this->telemetry->span('child')->getParentSpanId());
    }

    public function testMeterIsAvailableWhenTracingDisabled(): void
    {
        ScopedInstrumentation::create('danilovl.test', false)->meter()->createCounter('c')->add(1);

        $this->assertArrayHasKey('c', $this->telemetry->collectMetrics());
    }
}
