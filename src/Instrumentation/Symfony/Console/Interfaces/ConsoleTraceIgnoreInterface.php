<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::CONSOLE_TRACE_IGNORE)]
interface ConsoleTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, ConsoleCommandEvent $event): bool;
}
