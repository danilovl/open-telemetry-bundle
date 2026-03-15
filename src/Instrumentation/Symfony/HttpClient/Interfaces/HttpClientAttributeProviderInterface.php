<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces;

use Danilovl\OpenTelemetryBundle\Instrumentation\Interfaces\SpanAttributeProviderInterface;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(InstrumentationTags::HTTP_CLIENT_ATTRIBUTE_PROVIDER)]
interface HttpClientAttributeProviderInterface extends SpanAttributeProviderInterface {}
