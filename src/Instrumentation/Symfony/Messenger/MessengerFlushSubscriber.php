<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\LongRunningCommandInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\TelemetryFlusher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\{
    WorkerMessageFailedEvent,
    WorkerMessageHandledEvent
};

final class MessengerFlushSubscriber implements EventSubscriberInterface
{
    private bool $isLongRunningCommand = false;

    /**
     * @param iterable<LongRunningCommandInterface> $longRunningCommands
     */
    public function __construct(
        private readonly TelemetryFlusher $flusher,
        #[AutowireIterator(InstrumentationTags::MESSENGER_LONG_RUNNING_COMMAND)]
        private readonly iterable $longRunningCommands = [],
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$longRunningCommands',
            items: $this->longRunningCommands,
            expectedType: LongRunningCommandInterface::class
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 1_000],
            ConsoleEvents::TERMINATE => ['onTerminate', -1_000],
            WorkerMessageHandledEvent::class => ['onMessageProcessed', -1_024],
            WorkerMessageFailedEvent::class => ['onMessageProcessed', -1_024],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $commandName = $event->getCommand()?->getName();

        if ($commandName === null) {
            return;
        }

        foreach ($this->longRunningCommands as $longRunningCommand) {
            if ($longRunningCommand->isLongRunning($commandName)) {
                $this->isLongRunningCommand = true;

                return;
            }
        }
    }

    public function onTerminate(): void
    {
        $this->isLongRunningCommand = false;
    }

    public function onMessageProcessed(): void
    {
        if (!$this->isLongRunningCommand) {
            return;
        }

        $this->flusher->flush();
    }
}
