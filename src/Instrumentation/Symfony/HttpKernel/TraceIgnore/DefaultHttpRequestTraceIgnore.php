<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpRequestTraceIgnoreInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class DefaultHttpRequestTraceIgnore implements HttpRequestTraceIgnoreInterface
{
    /**
     * @var string[]
     */
    private array $ignoredPrefixes = [
        '/_wdt/',
        '/_profiler/',
    ];

    public function shouldIgnore(string $spanName, RequestEvent $event): bool
    {
        $pathInfo = $event->getRequest()->getPathInfo();

        return array_any($this->ignoredPrefixes, static fn (string $prefix): bool => str_starts_with($pathInfo, $prefix));

    }
}
