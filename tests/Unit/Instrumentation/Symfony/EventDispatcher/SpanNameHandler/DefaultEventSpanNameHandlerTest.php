<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\EventDispatcher\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler\DefaultEventSpanNameHandler;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

class DefaultEventSpanNameHandlerTest extends TestCase
{
    private DefaultEventSpanNameHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DefaultEventSpanNameHandler;
    }

    #[DataProvider('provideProcessCases')]
    public function testProcess(string $spanName, object $event, ?string $eventName, ?string $expected): void
    {
        $result = $this->handler->process($spanName, $event, $eventName);

        if ($expected !== null) {
            $this->assertSame($expected, $result);
        } else {
            $this->assertStringStartsWith('event.dispatch ', $result);
        }
    }

    public function testProcessWithNamespacedClass(): void
    {
        $event = new Request;
        $result = $this->handler->process('event.dispatch', $event);

        $this->assertSame('event.dispatch Request', $result);
    }

    public function testProcessReturnsSpanNameWhenShortNameEmpty(): void
    {
        $event = new stdClass;
        $result = $this->handler->process('fallback', $event);
        $this->assertNotEmpty($result);
    }

    public static function provideProcessCases(): Generator
    {
        yield 'stdClass event' => [
            'event.dispatch',
            new stdClass,
            null,
            'event.dispatch stdClass'
        ];
        yield 'namespaced event uses short name' => [
            'event.dispatch',
            new class ( ) {},
            null,
            null
        ];
    }
}
