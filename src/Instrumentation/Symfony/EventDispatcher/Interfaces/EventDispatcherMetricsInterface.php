<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces;

use Throwable;

interface EventDispatcherMetricsInterface
{
    public function recordDispatch(object $event, ?string $eventName, float $durationMs): void;

    public function recordError(object $event, ?string $eventName, Throwable $throwable, float $durationMs): void;
}
