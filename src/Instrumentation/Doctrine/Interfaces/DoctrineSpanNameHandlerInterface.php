<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::DOCTRINE_SPAN_NAME_HANDLER)]
interface DoctrineSpanNameHandlerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function process(string $spanName, array $context): string;
}
