<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Interfaces;

interface SpanAttributeProviderInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|array<string, scalar>|null>
     */
    public function provide(array $context): array;
}
