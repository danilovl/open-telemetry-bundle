<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Traceable
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $name = null,
        public array $attributes = [],
    ) {}
}
