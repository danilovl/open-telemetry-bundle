<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Messenger;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerTraceIgnoreInterface;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyMessengerMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\MessageBusTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Stamp\TraceContextStamp;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Integration\Message\SimpleMessage;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\{
    Envelope,
    MessageBus
};
use Symfony\Component\Messenger\Middleware\{
    MiddlewareInterface,
    StackInterface
};
use Symfony\Component\Messenger\Stamp\{
    ReceivedStamp,
    SentStamp,
    StampInterface
};
use Symfony\Component\Messenger\Transport\Serialization\{
    PhpSerializer,
    Serializer
};
use Throwable;

class MessageBusTracingMiddlewareTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private ?Envelope $handledEnvelope = null;

    private ?Throwable $handlerException = null;

    private SpyMessengerMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new SpyMessengerMetrics;
    }

    /**
     * @param array<int, object> $ignores
     */
    private function createBus(array $ignores = []): MessageBus
    {
        /** @var iterable<MessengerTraceIgnoreInterface> $ignores */
        $tracing = new MessageBusTracingMiddleware(
            instrumentation: ScopedInstrumentation::create(MessageBusTracingMiddleware::INSTRUMENTATION_NAME),
            messengerTraceIgnores: $ignores,
            messengerMetrics: $this->metrics,
        );

        $terminal = new class($this) implements MiddlewareInterface {
            public function __construct(private readonly MessageBusTracingMiddlewareTest $test) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $this->test->handleTerminal($envelope);
            }
        };

        return new MessageBus([$tracing, $terminal]);
    }

    /**
     * @internal called by the terminal middleware ("transport" / "handler")
     */
    public function handleTerminal(Envelope $envelope): Envelope
    {
        $this->handledEnvelope = $envelope;

        if ($this->handlerException !== null) {
            throw $this->handlerException;
        }

        if ($envelope->last(ReceivedStamp::class) === null) {
            return $envelope->with(new SentStamp('App\\Transport', 'async'));
        }

        return $envelope;
    }

    private function consumedEnvelope(StampInterface ...$stamps): Envelope
    {
        $message = new SimpleMessage;
        $receivedStamp = new ReceivedStamp('async');

        return new Envelope($message, [$receivedStamp, ...$stamps]);
    }

    public function testProducerAddsTraceContextStamp(): void
    {
        $this->createBus()->dispatch(new SimpleMessage);

        $span = $this->telemetry->span('publish async');
        $stamp = $this->handledEnvelope?->last(TraceContextStamp::class);

        $this->assertSame(SpanKind::KIND_PRODUCER, $span->getKind());
        $this->assertInstanceOf(TraceContextStamp::class, $stamp);
        $this->assertSame(
            sprintf('00-%s-%s-%02x', $span->getTraceId(), $span->getSpanId(), $span->getContext()->getTraceFlags()),
            $stamp->getHeaders()['traceparent'] ?? null
        );
        $this->assertSame(['message:publish'], $this->metrics->calls);
    }

    public function testConsumerContinuesProducerTrace(): void
    {
        $bus = $this->createBus();
        $bus->dispatch(new SimpleMessage);
        $producer = $this->telemetry->span('publish async');
        $sentEnvelope = $this->handledEnvelope;
        $this->assertNotNull($sentEnvelope);

        $received = $sentEnvelope->withoutAll(SentStamp::class)->with(new ReceivedStamp('async'));
        $this->telemetry->reset();

        $bus->dispatch($received);

        $consumer = $this->telemetry->span('process async');
        $this->assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
        $this->assertSame($producer->getTraceId(), $consumer->getTraceId(), 'Consumer must be in the producer trace');
        $this->assertSame($producer->getSpanId(), $consumer->getParentSpanId());
        $this->assertSame('async', $consumer->getAttributes()->get('messaging.destination.name'));
    }

    public function testStampSurvivesPhpSerialization(): void
    {
        $this->createBus()->dispatch(new SimpleMessage);
        $this->assertNotNull($this->handledEnvelope);

        $serializer = new PhpSerializer;
        $decoded = $serializer->decode($serializer->encode($this->handledEnvelope));

        $this->assertEquals($this->handledEnvelope->last(TraceContextStamp::class), $decoded->last(TraceContextStamp::class));
    }

    public function testStampSurvivesSymfonySerializer(): void
    {
        if (!class_exists(\Symfony\Component\Serializer\Serializer::class)) {
            $this->markTestSkipped('symfony/serializer is not installed');
        }

        $this->createBus()->dispatch(new SimpleMessage);
        $this->assertNotNull($this->handledEnvelope);

        $serializer = Serializer::create();
        $decoded = $serializer->decode($serializer->encode($this->handledEnvelope));

        $this->assertEquals($this->handledEnvelope->last(TraceContextStamp::class), $decoded->last(TraceContextStamp::class));
    }

    public function testMessagingAttributesAreSet(): void
    {
        $this->createBus()->dispatch(new SimpleMessage);

        $attributes = $this->telemetry->span('publish async')->getAttributes();

        $this->assertSame('symfony.messenger', $attributes->get('messaging.system'));
        $this->assertSame('publish', $attributes->get('messaging.operation.name'));
        $this->assertSame('async', $attributes->get('messaging.destination.name'));
    }

    public function testRedispatchKeepsOriginalStamp(): void
    {
        $original = new TraceContextStamp(['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

        $message = new SimpleMessage;
        $envelope = new Envelope($message, [$original]);

        $this->createBus()->dispatch($envelope);

        $stamps = $this->handledEnvelope?->all(TraceContextStamp::class) ?? [];
        $this->assertCount(1, $stamps, 'Retry / failure transport re-dispatch must not stack stamps');
        $this->assertSame($original, $stamps[0]);
    }

    public function testConsumerWithoutStampStartsNewTrace(): void
    {
        $this->createBus()->dispatch($this->consumedEnvelope());

        $span = $this->telemetry->span('process async');
        $this->assertSame('0000000000000000', $span->getParentSpanId());
    }

    public function testConsumerInvalidStampNewTrace(): void
    {
        $invalidStamp = new TraceContextStamp(['traceparent' => 'garbage']);

        $this->createBus()->dispatch($this->consumedEnvelope($invalidStamp));

        $span = $this->telemetry->span('process async');
        $this->assertSame('0000000000000000', $span->getParentSpanId());
    }

    public function testHandlerExceptionIsRecorded(): void
    {
        $this->handlerException = new RuntimeException('handler failed');

        try {
            $this->createBus()->dispatch($this->consumedEnvelope());
            $this->fail('Exception expected');
        } catch (RuntimeException) {
        }

        $span = $this->telemetry->span('process async');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        $this->assertSame(['error:process'], $this->metrics->calls);
    }

    public function testIgnoredMessageIsNotTracedNorStamped(): void
    {
        $ignore = new class() implements MessengerTraceIgnoreInterface {
            public function shouldIgnore(string $spanName, Envelope $envelope): bool
            {
                return true;
            }
        };

        $this->createBus([$ignore])->dispatch(new SimpleMessage);

        $this->assertCount(0, $this->telemetry->spans());
        $this->assertNull($this->handledEnvelope?->last(TraceContextStamp::class));
    }
}
