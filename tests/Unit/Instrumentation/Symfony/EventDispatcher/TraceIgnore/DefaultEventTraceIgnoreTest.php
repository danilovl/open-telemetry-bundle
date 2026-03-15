<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\EventDispatcher\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore\DefaultEventTraceIgnore;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};

class DefaultEventTraceIgnoreTest extends TestCase
{
    private DefaultEventTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new DefaultEventTraceIgnore;
    }

    public function testShouldIgnoreVendorClass(): void
    {
        $vendorEvent = new Request;

        $this->assertTrue($this->traceIgnore->shouldIgnore('event.dispatch', $vendorEvent));
    }

    public function testShouldNotIgnoreTestClass(): void
    {
        $testEvent = new class ( ) {};

        $this->assertFalse($this->traceIgnore->shouldIgnore('event.dispatch', $testEvent));
    }

    public function testShouldNotIgnoreBuiltinClass(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('event.dispatch', new stdClass));
    }

    public function testShouldIgnoreAnotherVendorClass(): void
    {
        $vendorEvent = new Response;

        $this->assertTrue($this->traceIgnore->shouldIgnore('event.dispatch', $vendorEvent, 'kernel.response'));
    }

    public function testShouldNotIgnoreProjectClass(): void
    {
        $projectEvent = new DefaultEventTraceIgnore;

        $this->assertFalse($this->traceIgnore->shouldIgnore('event.dispatch', $projectEvent));
    }
}
