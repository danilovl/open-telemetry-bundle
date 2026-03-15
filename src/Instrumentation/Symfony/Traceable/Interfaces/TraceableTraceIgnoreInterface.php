<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::TRACEABLE_TRACE_IGNORE)]
interface TraceableTraceIgnoreInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function shouldIgnore(string $spanName, array $context): bool;
}
