<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpRequestTraceIgnoreInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class DefaultHttpRequestTraceIgnore implements HttpRequestTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, RequestEvent $event): bool
    {
        return str_starts_with($event->getRequest()->getPathInfo(), '/_wdt/');
    }
}
