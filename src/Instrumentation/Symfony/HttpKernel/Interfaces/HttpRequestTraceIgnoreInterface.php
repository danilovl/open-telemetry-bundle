<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AutoconfigureTag(InstrumentationTags::HTTP_REQUEST_TRACE_IGNORE)]
interface HttpRequestTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, RequestEvent $event): bool;
}
