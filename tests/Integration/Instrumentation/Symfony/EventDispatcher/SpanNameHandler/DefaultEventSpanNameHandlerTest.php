<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Symfony\EventDispatcher\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler\DefaultEventSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Event\{
    UserRegisteredEvent
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Event\{
    OrderShippedEvent,
    PaymentProcessedEvent};
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\RequestStack;

class DefaultEventSpanNameHandlerTest extends TestCase
{
    private DefaultEventSpanNameHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DefaultEventSpanNameHandler;
    }

    #[DataProvider('provideProcessCases')]
    public function testProcess(object $event, ?string $eventName, string $expected): void
    {
        $this->assertSame($expected, $this->handler->process('span', $event, $eventName));
    }

    public function testSpanNameIgnored(): void
    {
        $event = new UserRegisteredEvent;

        $this->assertSame(
            'event.dispatch UserRegisteredEvent',
            $this->handler->process('any.span.name', $event)
        );

        $this->assertSame(
            'event.dispatch UserRegisteredEvent',
            $this->handler->process('', $event)
        );
    }

    public function testEventNameIgnored(): void
    {
        $event = new UserRegisteredEvent;

        $this->assertSame(
            'event.dispatch UserRegisteredEvent',
            $this->handler->process('span', $event, 'kernel.request')
        );

        $this->assertSame(
            'event.dispatch UserRegisteredEvent',
            $this->handler->process('span', $event)
        );
    }

    public function testAnonymousClassReturnsEventDispatchPrefix(): void
    {
        $event = new class ( ) {};
        $result = $this->handler->process('original.span', $event);

        $this->assertStringStartsWith('event.dispatch ', $result);
    }

    #[DataProvider('provideShortNameExtractedCases')]
    public function testShortNameExtracted(object $event, string $expected): void
    {
        $this->assertSame($expected, $this->handler->process('span', $event));
    }

    public function testResultStartsWithEventDispatch(): void
    {
        $result = $this->handler->process('span', new OrderShippedEvent);
        $this->assertStringStartsWith('event.dispatch ', $result);
    }

    public function testDeepNamespaceOnlyShortNameReturned(): void
    {
        $event = new class ( ) {};
        $reflection = new ReflectionClass($event);
        $shortName = $reflection->getShortName();
        $result = $this->handler->process('span', $event);
        $this->assertStringContainsString($shortName, $result);
        $this->assertStringStartsWith('event.dispatch ', $result);
    }

    #[DataProvider('provideVendorClassShortNameCases')]
    public function testVendorClassShortName(object $event, string $expected): void
    {
        $this->assertSame($expected, $this->handler->process('span', $event));
    }

    public function testProcessWithNullEventNameUsesClassShortName(): void
    {
        $event = new PaymentProcessedEvent;
        $resultWithNull = $this->handler->process('span', $event, null);
        $resultWithName = $this->handler->process('span', $event, 'payment.processed');
        $this->assertSame($resultWithNull, $resultWithName);
        $this->assertSame('event.dispatch PaymentProcessedEvent', $resultWithNull);
    }

    public function testOutputDoesNotContainBackslash(): void
    {
        $result = $this->handler->process('span', new RequestStack, 'kernel.request');
        $this->assertStringNotContainsString('\\', $result);
    }

    public function testOutputDoesNotContainNamespacePart(): void
    {
        $result = $this->handler->process('span', new RequestStack);
        $this->assertStringNotContainsString('HttpFoundation', $result);
    }

    #[DataProvider('provideMultipleCallsReturnSameResultCases')]
    public function testMultipleCallsReturnSameResult(object $event, string $expected): void
    {
        $first = $this->handler->process('span', $event);
        $second = $this->handler->process('span', $event);
        $this->assertSame($first, $second);
        $this->assertSame($expected, $first);
    }

    public static function provideProcessCases(): Generator
    {
        yield 'UserRegisteredEvent' => [
            new UserRegisteredEvent, null, 'event.dispatch UserRegisteredEvent'
        ];
        yield 'OrderShippedEvent' => [
            new OrderShippedEvent, null, 'event.dispatch OrderShippedEvent'
        ];
        yield 'PaymentProcessedEvent' => [
            new PaymentProcessedEvent, 'payment.processed', 'event.dispatch PaymentProcessedEvent'
        ];
        yield 'RequestStack (vendor)' => [
            new RequestStack, 'kernel.request', 'event.dispatch RequestStack'
        ];
    }

    public static function provideShortNameExtractedCases(): Generator
    {
        yield 'no namespace' => [new UserRegisteredEvent, 'event.dispatch UserRegisteredEvent'];
        yield 'symfony class' => [new RequestStack, 'event.dispatch RequestStack'];
        yield 'kernel event' => [new OrderShippedEvent, 'event.dispatch OrderShippedEvent'];
    }

    public static function provideVendorClassShortNameCases(): Generator
    {
        yield 'RequestStack' => [new RequestStack, 'event.dispatch RequestStack'];
        yield 'RequestEvent class' => [
            new stdClass,
            'event.dispatch stdClass'
        ];
    }

    public static function provideMultipleCallsReturnSameResultCases(): Generator
    {
        yield 'same event twice' => [new UserRegisteredEvent, 'event.dispatch UserRegisteredEvent'];
        yield 'payment event' => [new PaymentProcessedEvent, 'event.dispatch PaymentProcessedEvent'];
    }
}
