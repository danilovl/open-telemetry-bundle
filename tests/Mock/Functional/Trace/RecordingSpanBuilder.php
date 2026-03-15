<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace;

use OpenTelemetry\API\Trace\{
    SpanBuilderInterface,
    SpanContextInterface,
    SpanInterface
};
use OpenTelemetry\Context\ContextInterface;
use Override;

class RecordingSpanBuilder implements SpanBuilderInterface
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param list<RecordingSpan> $registry
     */
    public function __construct(
        private readonly string $spanName,
        private array &$registry // @phpstan-ignore property.onlyWritten
    ) {}

    #[Override]
    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    #[Override]
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    #[Override]
    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param iterable<string, mixed> $attributes
     */
    #[Override]
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }

        return $this;
    }

    #[Override]
    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    #[Override]
    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    #[Override]
    public function startSpan(): SpanInterface
    {
        $span = new RecordingSpan($this->spanName);
        foreach ($this->attributes as $key => $value) {
            if ($key !== '' && (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || is_array($value) || $value === null)) {
                $span->setAttribute($key, $value);
            }
        }
        $this->registry[] = $span;

        return $span;
    }
}
