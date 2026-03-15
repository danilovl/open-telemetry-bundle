<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces;

use Danilovl\OpenTelemetryBundle\Instrumentation\Interfaces\SpanAttributeProviderInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::REDIS_ATTRIBUTE_PROVIDER)]
interface RedisAttributeProviderInterface extends SpanAttributeProviderInterface {}
