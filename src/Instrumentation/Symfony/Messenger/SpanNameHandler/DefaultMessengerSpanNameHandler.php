<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerSpanNameHandlerInterface;
use ReflectionClass;
use Symfony\Component\Messenger\Envelope;

final class DefaultMessengerSpanNameHandler implements MessengerSpanNameHandlerInterface
{
    public function process(string $spanName, Envelope $envelope): string
    {
        if ($spanName !== 'messenger.publish' && $spanName !== 'messenger.process') {
            return $spanName;
        }

        $message = $envelope->getMessage();
        $reflection = new ReflectionClass($message);

        return sprintf('%s %s', $spanName, $reflection->getShortName());
    }
}
