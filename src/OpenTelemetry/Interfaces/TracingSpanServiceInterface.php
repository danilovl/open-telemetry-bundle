<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use Throwable;

/**
 * Fluent service for managing OpenTelemetry spans within application code.
 *
 * Wraps the low-level OpenTelemetry Tracer API into a simple fluent interface
 * that handles span lifecycle, context propagation, and attribute/event recording.
 *
 * Typical usage:
 *   $this->spanService->start('my.operation')
 *       ->setAttribute('key', 'value')
 *       ->addEvent('something happened')
 *       ->end();
 *
 * Each {@see start()} call creates a child span of the currently active span
 * and makes it the active context. {@see end()} finalises the span and
 * restores the previous context automatically.
 *
 * The concrete implementation is {@see \Danilovl\OpenTelemetryBundle\OpenTelemetry\Service\TracingSpanService}.
 */
interface TracingSpanServiceInterface
{
    /**
     * Starts a new child span with the given name and makes it the active span.
     * Must be paired with a corresponding {@see end()} call.
     */
    public function start(string $name): self;

    /**
     * Creates a new root span detached from the current trace context.
     * Useful when you need to start an independent trace (e.g. for background jobs).
     */
    public function createNewCurrent(): self;

    /**
     * Ends the current span and restores the previous active context.
     */
    public function end(): void;

    public function setAttribute(string $key, mixed $value): self;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function setAttributes(array $attributes): self;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addEvent(string $message, array $attributes = []): self;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function addErrorEvent(string $message, array $attributes = []): self;

    /**
     * @param array<string|int, mixed> $attributes
     */
    public function recordHandledException(Throwable $exception, array $attributes = []): self;

    public function markOutcomeAsFailure(?string $description = null): self;

    public function markOutcomeAsSuccess(?string $description = null): self;
}
