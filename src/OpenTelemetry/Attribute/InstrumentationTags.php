<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute;

final class InstrumentationTags
{
    public const string CACHE_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.cache.attribute_provider';
    public const string CACHE_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.cache.span_name_handler';
    public const string CACHE_TRACE_IGNORE = 'danilovl.open_telemetry.cache.trace_ignore';
    public const string CONSOLE_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.console.attribute_provider';
    public const string CONSOLE_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.console.span_name_handler';
    public const string CONSOLE_TRACE_IGNORE = 'danilovl.open_telemetry.console.trace_ignore';
    public const string DOCTRINE_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.doctrine.span_name_handler';
    public const string DOCTRINE_TRACE_IGNORE = 'danilovl.open_telemetry.doctrine.trace_ignore';
    public const string EVENT_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.event.attribute_provider';
    public const string EVENT_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.event.span_name_handler';
    public const string EVENT_TRACE_IGNORE = 'danilovl.open_telemetry.event.trace_ignore';
    public const string HTTP_CLIENT_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.http_client.attribute_provider';
    public const string HTTP_CLIENT_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.http_client.span_name_handler';
    public const string HTTP_CLIENT_TRACE_IGNORE = 'danilovl.open_telemetry.http_client.trace_ignore';
    public const string HTTP_REQUEST_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.http_request.attribute_provider';
    public const string HTTP_REQUEST_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.http_request.span_name_handler';
    public const string HTTP_REQUEST_TRACE_IGNORE = 'danilovl.open_telemetry.http_request.trace_ignore';
    public const string MAILER_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.mailer.span_name_handler';
    public const string MAILER_TRACE_IGNORE = 'danilovl.open_telemetry.mailer.trace_ignore';
    public const string MESSENGER_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.messenger.attribute_provider';
    public const string MESSENGER_LONG_RUNNING_COMMAND = 'danilovl.open_telemetry.messenger.long_running_command';
    public const string MESSENGER_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.messenger.span_name_handler';
    public const string MESSENGER_TRACE_IGNORE = 'danilovl.open_telemetry.messenger.trace_ignore';
    public const string REDIS_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.redis.attribute_provider';
    public const string REDIS_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.redis.span_name_handler';
    public const string REDIS_TRACE_IGNORE = 'danilovl.open_telemetry.redis.trace_ignore';
    public const string TRACEABLE_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.traceable.attribute_provider';
    public const string TRACEABLE_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.traceable.span_name_handler';
    public const string TRACEABLE_TRACE_IGNORE = 'danilovl.open_telemetry.traceable.trace_ignore';
    public const string TWIG_ATTRIBUTE_PROVIDER = 'danilovl.open_telemetry.twig.attribute_provider';
    public const string TWIG_SPAN_NAME_HANDLER = 'danilovl.open_telemetry.twig.span_name_handler';
    public const string TWIG_TRACE_IGNORE = 'danilovl.open_telemetry.twig.trace_ignore';
    public const string SPAN_PROCESSOR = 'danilovl.open_telemetry.span_processor';
}
