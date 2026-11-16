<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Mailer;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\MailerTracingSubscriber;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mailer\{
    Envelope,
    SentMessage
};
use Symfony\Component\Mailer\Event\{
    FailedMessageEvent,
    MessageEvent,
    SentMessageEvent
};
use Symfony\Component\Mime\{
    Address,
    Email
};

class MailerTracingSubscriberTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private MailerTracingSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new MailerTracingSubscriber(
            ScopedInstrumentation::create(MailerTracingSubscriber::INSTRUMENTATION_NAME)
        );
    }

    private function email(): Email
    {
        return new Email()->from('from@example.com')->to('to@example.com')->subject('Hi')->text('Body');
    }

    private function envelope(): Envelope
    {
        $sender = new Address('from@example.com');
        $recipient = new Address('to@example.com');

        return new Envelope($sender, [$recipient]);
    }

    private function openSpans(): int
    {
        /** @var array<int, mixed> $spans */
        $spans = new ReflectionProperty(MailerTracingSubscriber::class, 'spans')->getValue($this->subscriber);

        return count($spans);
    }

    public function testSentMessageCreatesProducerSpan(): void
    {
        $email = $this->email();
        $envelope = $this->envelope();
        $messageEvent = new MessageEvent($email, $envelope, 'smtp://localhost');
        $sentMessage = new SentMessage($email, $envelope);

        $this->subscriber->onMessage($messageEvent);
        $this->subscriber->onSent(new SentMessageEvent($sentMessage));

        $span = $this->telemetry->span('mailer send');
        $this->assertSame(SpanKind::KIND_PRODUCER, $span->getKind());
        $this->assertSame('smtp://localhost', $span->getAttributes()->get('email.transport'));
        $this->assertNotNull($span->getAttributes()->get('email.message_id'));
        $this->assertSame(0, $this->openSpans());
    }

    public function testQueuedMessageDoesNotOpenSpan(): void
    {
        $messageEvent = new MessageEvent($this->email(), $this->envelope(), 'async', true);

        $this->subscriber->onMessage($messageEvent);

        $this->assertSame(0, $this->openSpans(), 'Queued message must not leave an unfinished span behind');
        $this->assertCount(0, $this->telemetry->spans());
    }

    public function testFailedMessageRecordsError(): void
    {
        $email = $this->email();
        $messageEvent = new MessageEvent($email, $this->envelope(), 'smtp://localhost');
        $error = new RuntimeException('SMTP down');

        $this->subscriber->onMessage($messageEvent);
        $this->subscriber->onFailed(new FailedMessageEvent($email, $error));

        $span = $this->telemetry->span('mailer send');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('SMTP down', $span->getStatus()->getDescription());
        $this->assertSame(0, $this->openSpans());
    }
}
