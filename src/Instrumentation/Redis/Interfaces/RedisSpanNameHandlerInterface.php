<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::REDIS_SPAN_NAME_HANDLER)]
interface RedisSpanNameHandlerInterface
{
    public function process(string $spanName, string $command, string $key): string;
}
