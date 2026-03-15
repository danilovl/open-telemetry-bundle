<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper;

use Danilovl\OpenTelemetryBundle\Instrumentation\Interfaces\SpanAttributeProviderInterface;
use OpenTelemetry\API\Trace\SpanInterface;

final readonly class SpanAttributeEnricher
{
    /**
     * @param iterable<SpanAttributeProviderInterface> $providers
     * @param array<string, mixed> $context
     */
    public static function enrich(
        SpanInterface $span,
        iterable $providers,
        array $context,
    ): void {
        foreach ($providers as $provider) {
            foreach ($provider->provide($context) as $key => $value) {
                if ($key === '') {
                    continue;
                }

                $span->setAttribute($key, $value);
            }
        }
    }
}
