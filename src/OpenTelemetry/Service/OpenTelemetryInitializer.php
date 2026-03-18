<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Service;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OpenTelemetryInitializer implements EventSubscriberInterface
{
    private bool $initialized = false;

    public function __construct(private readonly OpenTelemetryFactory $factory) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', PHP_INT_MAX],
            ConsoleEvents::COMMAND => ['onConsoleCommand', PHP_INT_MAX],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->initialize();
    }

    public function onConsoleCommand(): void
    {
        $this->initialize();
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->factory->initializeSdk();
        $this->initialized = true;
    }
}
