<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Messenger\Envelope;

#[AutoconfigureTag(InstrumentationTags::MESSENGER_SPAN_NAME_HANDLER)]
interface MessengerSpanNameHandlerInterface
{
    public function process(string $spanName, Envelope $envelope): string;
}
