<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::DOCTRINE_TRACE_IGNORE)]
interface DoctrineTraceIgnoreInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function shouldIgnore(string $spanName, array $context): bool;
}
