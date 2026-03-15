<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Symfony\EventDispatcher\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore\DefaultEventTraceIgnore;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Event\NonVendorEvent;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpFoundation\RequestStack;

class DefaultEventTraceIgnoreTest extends TestCase
{
    private DefaultEventTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new DefaultEventTraceIgnore;
    }

    #[DataProvider('provideVendorEventIsIgnoredCases')]
    public function testVendorEventIsIgnored(object $event): void
    {
        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testNonVendorEventIsNotIgnored(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', new NonVendorEvent));
    }

    public function testBuiltinClassWithNoFileIsNotIgnored(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', new stdClass));
    }

    public function testAnonymousObjectIsNotIgnored(): void
    {
        $event = new class ( ) {};

        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testSpanNameDoesNotAffectResult(): void
    {
        $event = new Processor;

        $this->assertTrue($this->traceIgnore->shouldIgnore('', $event));
        $this->assertTrue($this->traceIgnore->shouldIgnore('any.name', $event));
    }

    public function testEventNameDoesNotAffectResult(): void
    {
        $event = new Processor;

        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event, 'kernel.request'));
        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    #[DataProvider('provideNonVendorObjectsNotIgnoredCases')]
    public function testNonVendorObjectsNotIgnored(object $event): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $event));
    }

    public static function provideVendorEventIsIgnoredCases(): Generator
    {
        yield 'Symfony Processor (vendor)' => [new Processor];
        yield 'Symfony RequestStack (vendor)' => [new RequestStack];
    }

    public static function provideNonVendorObjectsNotIgnoredCases(): Generator
    {
        yield 'NonVendorEvent' => [new NonVendorEvent];
        yield 'stdClass' => [new stdClass];
    }
}
