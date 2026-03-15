<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces;

use Throwable;

interface TracingSpanServiceInterface
{
    public function start(string $name): self;

    public function createNewCurrent(): self;

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
