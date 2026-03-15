<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::TRACEABLE_SPAN_NAME_HANDLER)]
interface TraceableSpanNameHandlerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function process(string $spanName, array $context): string;
}
