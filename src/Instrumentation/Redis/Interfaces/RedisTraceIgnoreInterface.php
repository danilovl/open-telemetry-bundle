<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::REDIS_TRACE_IGNORE)]
interface RedisTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, string $command, string $key): bool;
}
