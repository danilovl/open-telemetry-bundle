<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class TracerShutdownSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onTerminate', -PHP_INT_MAX + 2],
            ConsoleEvents::TERMINATE => ['onTerminate', -PHP_INT_MAX + 2],
        ];
    }

    public function onTerminate(): void
    {
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProviderInterface) {
            $tracerProvider->shutdown();
        }
    }
}
