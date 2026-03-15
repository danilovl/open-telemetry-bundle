<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::EVENT_SPAN_NAME_HANDLER)]
interface EventSpanNameHandlerInterface
{
    public function process(string $spanName, object $event, ?string $eventName = null): string;
}
