<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Messenger\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\SpanNameHandler\DefaultMessengerSpanNameHandler;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

class DefaultMessengerSpanNameHandlerTest extends TestCase
{
    private DefaultMessengerSpanNameHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DefaultMessengerSpanNameHandler;
    }

    #[DataProvider('provideProcessCases')]
    public function testProcess(string $spanName, object $message, string $expected): void
    {
        $envelope = new Envelope($message);
        $result = $this->handler->process($spanName, $envelope);
        $this->assertSame($expected, $result);
    }

    public function testProcessUsesShortClassName(): void
    {
        $message = new class ( ) {};
        $envelope = new Envelope($message);
        $result = $this->handler->process('messenger.publish', $envelope);

        $this->assertStringStartsWith('messenger.publish ', $result);
        $this->assertNotSame('messenger.publish ', $result);
    }

    public static function provideProcessCases(): Generator
    {
        yield 'messenger.publish' => [
            'messenger.publish',
            new stdClass,
            'messenger.publish stdClass'
        ];
        yield 'messenger.process' => [
            'messenger.process',
            new stdClass,
            'messenger.process stdClass'
        ];
        yield 'other span name returned as-is' => [
            'messenger.other',
            new stdClass,
            'messenger.other'
        ];
        yield 'custom span name returned as-is' => [
            'some.custom.span',
            new stdClass,
            'some.custom.span'
        ];
    }
}
