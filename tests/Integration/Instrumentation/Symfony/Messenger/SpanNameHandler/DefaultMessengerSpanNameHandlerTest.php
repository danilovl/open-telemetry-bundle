<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Symfony\Messenger\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\SpanNameHandler\DefaultMessengerSpanNameHandler;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Message\{
    SendEmailMessage
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Message\{
    OrderCreatedMessage,
    SimpleMessage
};
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

class DefaultMessengerSpanNameHandlerTest extends TestCase
{
    private DefaultMessengerSpanNameHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DefaultMessengerSpanNameHandler;
    }

    #[DataProvider('provideKnownSpanNamesReturnShortClassNameCases')]
    public function testKnownSpanNamesReturnShortClassName(string $spanName, object $message, string $expected): void
    {
        $envelope = new Envelope($message);

        $this->assertSame($expected, $this->handler->process($spanName, $envelope));
    }

    #[DataProvider('provideUnknownSpanNamesReturnOriginalCases')]
    public function testUnknownSpanNamesReturnOriginal(string $spanName): void
    {
        $envelope = new Envelope(new SimpleMessage);

        $this->assertSame($spanName, $this->handler->process($spanName, $envelope));
    }

    public function testAnonymousMessageClassUsesAnonymousName(): void
    {
        $message = new class ( ) {};
        $envelope = new Envelope($message);
        $result = $this->handler->process('messenger.publish', $envelope);

        $this->assertStringStartsWith('messenger.publish ', $result);
    }

    #[DataProvider('provideResultContainsSpanNamePrefixCases')]
    public function testResultContainsSpanNamePrefix(string $spanName, object $message): void
    {
        $envelope = new Envelope($message);
        $result = $this->handler->process($spanName, $envelope);

        $this->assertStringStartsWith($spanName . ' ', $result);
    }

    public static function provideKnownSpanNamesReturnShortClassNameCases(): Generator
    {
        yield 'messenger.publish + SimpleMessage' => [
            'messenger.publish', new SimpleMessage, 'messenger.publish SimpleMessage',
        ];
        yield 'messenger.process + SimpleMessage' => [
            'messenger.process', new SimpleMessage, 'messenger.process SimpleMessage',
        ];
        yield 'messenger.publish + OrderCreatedMessage' => [
            'messenger.publish', new OrderCreatedMessage, 'messenger.publish OrderCreatedMessage',
        ];
        yield 'messenger.process + SendEmailMessage' => [
            'messenger.process', new SendEmailMessage, 'messenger.process SendEmailMessage',
        ];
    }

    public static function provideUnknownSpanNamesReturnOriginalCases(): Generator
    {
        yield 'messenger.receive' => ['messenger.receive'];
        yield 'messenger.handle' => ['messenger.handle'];
        yield 'messenger.retry' => ['messenger.retry'];
        yield 'custom.span' => ['custom.span'];
        yield 'empty string' => [''];
    }

    public static function provideResultContainsSpanNamePrefixCases(): Generator
    {
        yield ['messenger.publish', new SimpleMessage];
        yield ['messenger.process', new OrderCreatedMessage];
        yield ['messenger.process', new SendEmailMessage];
    }
}
