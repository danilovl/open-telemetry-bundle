<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use Symfony\Component\Mailer\Event\{
    FailedMessageEvent,
    MessageEvent,
    SentMessageEvent
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\{
    MailerMetricsInterface,
    MailerSpanNameHandlerInterface,
    MailerTraceIgnoreInterface
};
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MailerTracingSubscriber implements EventSubscriberInterface
{
    /**
     * @var array<int, SpanInterface>
     */
    private array $spans = [];

    /**
     * @var array<int, int|float>
     */
    private array $startTimes = [];

    /**
     * @var array<int, string|null>
     */
    private array $transports = [];

    /**
     * @param iterable<MailerSpanNameHandlerInterface> $mailerSpanNameHandlers
     * @param iterable<MailerTraceIgnoreInterface> $mailerTraceIgnores
     * @param MailerMetricsInterface|null $mailerMetrics
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::MAILER_SPAN_NAME_HANDLER)]
        private readonly iterable $mailerSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::MAILER_TRACE_IGNORE)]
        private readonly iterable $mailerTraceIgnores = [],
        private readonly ?MailerMetricsInterface $mailerMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$mailerSpanNameHandlers',
            items: $this->mailerSpanNameHandlers,
            expectedType: MailerSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$mailerTraceIgnores',
            items: $this->mailerTraceIgnores,
            expectedType: MailerTraceIgnoreInterface::class
        );
    }

    /**
     * @return array<class-string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['onMessage', 1_000],
            SentMessageEvent::class => ['onSent', -1_000],
            FailedMessageEvent::class => ['onFailed', -1_000],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $spanName = 'mailer send';

        foreach ($this->mailerSpanNameHandlers as $mailerSpanNameHandler) {
            $spanName = $mailerSpanNameHandler->process($spanName, $event);
        }

        foreach ($this->mailerTraceIgnores as $mailerTraceIgnore) {
            if ($mailerTraceIgnore->shouldIgnore($spanName, $event)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'mailer send' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('transaction.type', 'messaging')
            ->setAttribute('mailer.system', 'mailer')
            ->setAttribute('email.class', $message::class)
            ->setAttribute('email.transport', $event->getTransport())
            ->startSpan();

        $this->spans[spl_object_id($message)] = $span;
        $this->startTimes[spl_object_id($message)] = hrtime(true);
        $this->transports[spl_object_id($message)] = $event->getTransport();
    }

    public function onSent(SentMessageEvent $event): void
    {
        $message = $event->getMessage()->getOriginalMessage();
        $key = spl_object_id($message);
        $span = $this->spans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $durationMs = isset($this->startTimes[$key]) ? (hrtime(true) - $this->startTimes[$key]) / 1_000_000 : 0;
        $transport = $this->transports[$key] ?? 'unknown';

        $this->mailerMetrics?->recordSent($message, $transport, $durationMs);

        $span->setAttribute('email.message_id', $event->getMessage()->getMessageId());
        $span->end();

        unset($this->spans[$key], $this->startTimes[$key], $this->transports[$key]);
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();
        $key = spl_object_id($message);
        $span = $this->spans[$key] ?? null;

        if (!$span instanceof SpanInterface) {
            return;
        }

        $error = $event->getError();

        $durationMs = isset($this->startTimes[$key]) ? (hrtime(true) - $this->startTimes[$key]) / 1_000_000 : 0;
        $transport = $this->transports[$key] ?? 'unknown';

        $this->mailerMetrics?->recordFailed($message, $transport, $error, $durationMs);

        $span->recordException($error);
        $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        $span->end();

        unset($this->spans[$key], $this->startTimes[$key], $this->transports[$key]);
    }
}
