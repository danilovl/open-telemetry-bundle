<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace;

use OpenTelemetry\API\Trace\{
    Span,
    SpanContext,
    SpanContextInterface,
    SpanInterface
};
use Override;
use Throwable;

class RecordingSpan extends Span
{
    private string $name;

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?string $statusCode = null;

    /** @var array<int, array{name: string, attributes: array<mixed>}> */
    private array $events = [];

    private bool $ended = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getStatusCode(): ?string
    {
        return $this->statusCode;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /** @return array<int, array{name: string, attributes: array<mixed>}> */
    public function getEvents(): array
    {
        return $this->events;
    }

    #[Override]
    public function getContext(): SpanContextInterface
    {
        return SpanContext::getInvalid();
    }

    #[Override]
    public function isRecording(): bool
    {
        return true;
    }

    /**
     * @param array<mixed> $value
     */
    #[Override]
    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param iterable<string, mixed> $attributes
     */
    #[Override]
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }

        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    #[Override]
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<string, mixed> $attributes
     */
    #[Override]
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        $attrs = [];
        foreach ($attributes as $k => $v) {
            $attrs[$k] = $v;
        }
        $this->events[] = ['name' => $name, 'attributes' => $attrs];

        return $this;
    }

    /**
     * @param iterable<string, mixed> $attributes
     */
    #[Override]
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        $eventAttributes = [
            'exception.type' => $exception::class,
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ];

        foreach ($attributes as $k => $v) {
            $eventAttributes[$k] = $v;
        }

        return $this->addEvent('exception', $eventAttributes);
    }

    #[Override]
    public function updateName(string $name): SpanInterface
    {
        $this->name = $name;

        return $this;
    }

    #[Override]
    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        $this->statusCode = $code;

        return $this;
    }

    #[Override]
    public function end(?int $endEpochNanos = null): void
    {
        $this->ended = true;
    }
}
