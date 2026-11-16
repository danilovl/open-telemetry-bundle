<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use Symfony\Component\HttpClient\Response\MockResponse;

final class SdkMockResponseFactory
{
    /** @var list<array{method: string, url: string, headers: array<string, list<string>>}> */
    public array $requests = [];

    public function reset(): void
    {
        $this->requests = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        /** @var array<string, list<string>> $headers */
        $headers = $options['normalized_headers'] ?? [];
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

        return new MockResponse('downstream-ok', ['http_code' => 200]);
    }
}
