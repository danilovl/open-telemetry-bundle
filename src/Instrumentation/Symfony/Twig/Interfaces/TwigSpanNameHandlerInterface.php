<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AutoconfigureTag(InstrumentationTags::TWIG_SPAN_NAME_HANDLER)]
interface TwigSpanNameHandlerInterface
{
    public function process(string $spanName, ResponseEvent $event): string;
}
