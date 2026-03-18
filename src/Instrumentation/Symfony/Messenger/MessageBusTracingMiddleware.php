<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\{
    MessengerAttributeProviderInterface,
    MessengerMetricsInterface,
    MessengerSpanNameHandlerInterface,
    MessengerTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\{
    SpanAttributeEnricher,
    TracingHelper
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\{
    MiddlewareInterface,
    StackInterface
};
use Symfony\Component\Messenger\Stamp\{
    ReceivedStamp,
    SentStamp,
    StampInterface,
    TransportNamesStamp
};
use Throwable;

final class MessageBusTracingMiddleware implements MiddlewareInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.messenger';

    /**
     * @param iterable<MessengerAttributeProviderInterface> $attributeProviders
     * @param iterable<MessengerSpanNameHandlerInterface> $messengerSpanNameHandlers
     * @param iterable<MessengerTraceIgnoreInterface> $messengerTraceIgnores
     * @param MessengerMetricsInterface|null $messengerMetrics
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::MESSENGER_ATTRIBUTE_PROVIDER)]
        private readonly iterable $attributeProviders = [],
        #[AutowireIterator(InstrumentationTags::MESSENGER_SPAN_NAME_HANDLER)]
        private readonly iterable $messengerSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::MESSENGER_TRACE_IGNORE)]
        private readonly iterable $messengerTraceIgnores = [],
        private readonly ?MessengerMetricsInterface $messengerMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$attributeProviders',
            items: $this->attributeProviders,
            expectedType: MessengerAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$messengerSpanNameHandlers',
            items: $this->messengerSpanNameHandlers,
            expectedType: MessengerSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$messengerTraceIgnores',
            items: $this->messengerTraceIgnores,
            expectedType: MessengerTraceIgnoreInterface::class
        );
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $spanKind = $this->resolveSpanKind($envelope);
        $operation = $spanKind === SpanKind::KIND_CONSUMER ? 'process' : 'publish';
        $spanName = sprintf('messenger.%s', $operation);
        $startTime = hrtime(true);

        foreach ($this->messengerSpanNameHandlers as $messengerSpanNameHandler) {
            $spanName = $messengerSpanNameHandler->process($spanName, $envelope);
        }

        foreach ($this->messengerTraceIgnores as $messengerTraceIgnore) {
            if ($messengerTraceIgnore->shouldIgnore($spanName, $envelope)) {
                return $stack->next()->handle($envelope, $stack);
            }
        }

        $spanAttributes = $this->buildMessagingAttributes($envelope, $operation);

        $destination = $spanAttributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME] ?? '';
        if ($spanName === sprintf('messenger.%s', $operation) && $destination !== '') {
            $spanName = sprintf('%s %s', $operation, $destination);
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? sprintf('messenger.%s', $operation) : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind($spanKind)
            ->setAttribute('messaging.message.type', $message::class);

        $span->setAttributes(TracingHelper::normalizeAttributeValues($spanAttributes));

        $span = $span->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->attributeProviders,
            context: ['envelope' => $envelope, 'message' => $message]
        );

        $scope = Context::storage()->attach(
            $span->storeInContext(Context::getCurrent())
        );

        try {
            $handledEnvelope = $stack->next()->handle($envelope, $stack);

            $handledAttributes = $this->buildMessagingAttributes($handledEnvelope, $operation);

            $span->setAttributes(TracingHelper::normalizeAttributeValues($handledAttributes));

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->messengerMetrics?->recordMessage($message, $operation, $handledAttributes, $durationMs);

            return $handledEnvelope;
        } catch (Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->messengerMetrics?->recordError($message, $operation, $spanAttributes, $e, $durationMs);

            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @return SpanKind::KIND_*
     */
    private function resolveSpanKind(Envelope $envelope): int
    {
        /** @var class-string<StampInterface> $receivedStampClass */
        $receivedStampClass = ReceivedStamp::class;

        return $envelope->last($receivedStampClass) instanceof ReceivedStamp
            ? SpanKind::KIND_CONSUMER
            : SpanKind::KIND_PRODUCER;
    }

    /**
     * @return array<string, string>
     */
    private function buildMessagingAttributes(Envelope $envelope, string $operation): array
    {
        $attributes = [];
        $transportName = $this->resolveTransportName($envelope);
        $messagingSystem = $this->resolveMessagingSystem($envelope, $transportName);

        $attributes[MessagingIncubatingAttributes::MESSAGING_SYSTEM] = $messagingSystem;
        $attributes[MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME] = $operation;

        if ($transportName !== null && $transportName !== '') {
            $attributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME] = $transportName;
        }

        return $attributes;
    }

    private function resolveTransportName(Envelope $envelope): ?string
    {
        $amqpReceivedStamp = $this->resolveAmqpReceivedStamp($envelope);

        if ($amqpReceivedStamp !== null && method_exists($amqpReceivedStamp, 'getQueueName')) {
            $queueName = (string) $amqpReceivedStamp->getQueueName();

            if ($queueName !== '') {
                return $queueName;
            }
        }

        $receivedStamp = $envelope->last(ReceivedStamp::class);

        if ($receivedStamp instanceof ReceivedStamp && $receivedStamp->getTransportName() !== '') {
            return $receivedStamp->getTransportName();
        }

        $transportNamesStamp = $envelope->last(TransportNamesStamp::class);

        if ($transportNamesStamp instanceof TransportNamesStamp) {
            $transportNames = $transportNamesStamp->getTransportNames();
            $transportName = $transportNames[0] ?? null;

            if (is_string($transportName) && $transportName !== '') {
                return $transportName;
            }
        }

        $sentStamp = $envelope->last(SentStamp::class);

        if ($sentStamp instanceof SentStamp) {
            $senderAlias = $sentStamp->getSenderAlias();

            if (is_string($senderAlias) && $senderAlias !== '') {
                return $senderAlias;
            }

            return $sentStamp->getSenderClass();
        }

        return null;
    }

    private function resolveMessagingSystem(Envelope $envelope, ?string $transportName): string
    {
        $sentStamp = $envelope->last(SentStamp::class);

        if ($this->resolveAmqpReceivedStamp($envelope) !== null) {
            return 'rabbitmq';
        }

        if ($sentStamp instanceof SentStamp && str_contains(mb_strtolower($sentStamp->getSenderClass()), 'amqp')) {
            return 'rabbitmq';
        }

        $normalizedTransportName = mb_strtolower((string) $transportName);

        if ($normalizedTransportName !== '' && (str_contains($normalizedTransportName, 'rabbit') || str_contains($normalizedTransportName, 'amqp'))) {
            return 'rabbitmq';
        }

        return 'symfony.messenger';
    }

    private function resolveAmqpReceivedStamp(Envelope $envelope): ?object
    {
        $amqpStampClass = 'Symfony\\Component\\Messenger\\Bridge\\Amqp\\Transport\\AmqpReceivedStamp';

        foreach ($envelope->all() as $stamps) {
            foreach ($stamps as $stamp) {
                if ($stamp::class === $amqpStampClass) {
                    return $stamp;
                }
            }
        }

        return null;
    }
}
