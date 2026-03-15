<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AutoconfigureTag(InstrumentationTags::TWIG_TRACE_IGNORE)]
interface TwigTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, ResponseEvent $event): bool;
}
