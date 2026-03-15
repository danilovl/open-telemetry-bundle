<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces;

use Danilovl\OpenTelemetryBundle\Instrumentation\Interfaces\SpanAttributeProviderInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::EVENT_ATTRIBUTE_PROVIDER)]
interface EventAttributeProviderInterface extends SpanAttributeProviderInterface {}
