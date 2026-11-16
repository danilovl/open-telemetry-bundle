<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\EventSubscriber;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\TelemetryFlusher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TelemetryFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TelemetryFlusher $flusher) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -PHP_INT_MAX + 2],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -PHP_INT_MAX + 2],
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->flusher->flush();
    }

    public function onConsoleTerminate(): void
    {
        $this->flusher->flush();
    }
}
