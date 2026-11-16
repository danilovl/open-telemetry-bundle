<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Propagation headers of the producer span. */
final readonly class TraceContextStamp implements StampInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(public array $headers = []) {}

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
