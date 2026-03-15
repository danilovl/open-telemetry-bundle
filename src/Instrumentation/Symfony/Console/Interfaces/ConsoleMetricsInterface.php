<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces;

use Symfony\Component\Console\Event\{
    ConsoleErrorEvent,
    ConsoleTerminateEvent
};

interface ConsoleMetricsInterface
{
    public function recordError(ConsoleErrorEvent $event): void;

    public function recordCommand(ConsoleTerminateEvent $event, string $commandName, float $durationMs): void;

    public function recordExitError(ConsoleTerminateEvent $event, string $commandName): void;
}
