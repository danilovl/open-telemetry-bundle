<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventSpanNameHandlerInterface;

final class DefaultEventSpanNameHandler implements EventSpanNameHandlerInterface
{
    public function process(string $spanName, object $event, ?string $eventName = null): string
    {
        $eventClass = $event::class;
        $separatorPosition = mb_strrpos($eventClass, '\\');

        $eventShortName = $separatorPosition === false
            ? $eventClass
            : mb_substr($eventClass, $separatorPosition + 1);

        if ($eventShortName === '') {
            return $spanName;
        }

        return sprintf('event.dispatch %s', $eventShortName);
    }
}
