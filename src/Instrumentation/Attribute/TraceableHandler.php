<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Attribute;

enum TraceableHandler
{
    /**
     * Handled by TraceableHookSubscriber via OpenTelemetry hook() API.
     * Use for regular services (non-controllers, non-commands).
     */
    case HOOK;

    /**
     * Handled by TraceableSubscriber::onController via KernelEvents.
     * Span lifecycle covers full HTTP request (open on CONTROLLER, close on TERMINATE).
     * Use for controllers, especially those with routes defined in YAML/XML config.
     */
    case CONTROLLER;

    /**
     * Handled by TraceableSubscriber::onConsoleCommand via ConsoleEvents.
     * Span lifecycle covers full command execution including exit code recording.
     * Use for console commands with routes defined outside PHP attributes.
     */
    case COMMAND;
}
