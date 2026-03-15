<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Mailer\Event\MessageEvent;

#[AutoconfigureTag(InstrumentationTags::MAILER_TRACE_IGNORE)]
interface MailerTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, MessageEvent $event): bool;
}
