<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::HTTP_CLIENT_TRACE_IGNORE)]
interface HttpClientTraceIgnoreInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function shouldIgnore(string $spanName, string $method, string $url, array $options): bool;
}
