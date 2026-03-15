<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventTraceIgnoreInterface;
use ReflectionClass;

final class DefaultEventTraceIgnore implements EventTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, object $event, ?string $eventName = null): bool
    {
        $reflection = new ReflectionClass($event);
        $fileName = $reflection->getFileName();

        return $fileName !== false && str_contains($fileName, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }
}
