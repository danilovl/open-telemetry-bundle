<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute;

enum SpanAttributes: string
{
    case TRACE_ID = 'traceId';
    case SPAN_ID = 'spanId';
    case SPAN_TYPE = 'type';
    case RECORDED_LOCATION = 'recordedLocation';
}
