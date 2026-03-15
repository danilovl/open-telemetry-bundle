<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::HTTP_CLIENT_SPAN_NAME_HANDLER)]
interface HttpClientSpanNameHandlerInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function process(string $spanName, string $method, string $url, array $options): string;
}
