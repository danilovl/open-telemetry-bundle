<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\HttpClient;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\{
    HttpClientAttributeProviderInterface,
    HttpClientSpanNameHandlerInterface,
    HttpClientTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyHttpClientMetrics;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\{
    HttpClientInterface,
    ResponseInterface
};

class HttpTracingMiddlewareTest extends TestCase
{
    use InMemoryTelemetryTrait;

    /** @var array<string, mixed>|null */
    private ?array $capturedOptions = null;

    private SpyHttpClientMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new SpyHttpClientMetrics;
    }

    /**
     * @param MockResponse|callable(): MockResponse $response
     * @param array<int, object> $nameHandlers
     * @param array<int, object> $ignores
     * @param array<int, object> $providers
     */
    private function createClient(
        MockResponse|callable $response,
        array $nameHandlers = [],
        array $ignores = [],
        array $providers = [],
        bool $tracingEnabled = true,
    ): HttpTracingMiddleware {
        $inner = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->capturedOptions = $options;

            return is_callable($response) ? $response() : $response;
        });

        /** @var iterable<HttpClientSpanNameHandlerInterface> $nameHandlers */
        /** @var iterable<HttpClientTraceIgnoreInterface> $ignores */
        /** @var iterable<HttpClientAttributeProviderInterface> $providers */
        return new HttpTracingMiddleware(
            client: $inner,
            instrumentation: ScopedInstrumentation::create(HttpTracingMiddleware::INSTRUMENTATION_NAME, $tracingEnabled),
            httpClientAttributeProviders: $providers,
            httpClientSpanNameHandlers: $nameHandlers,
            httpClientTraceIgnores: $ignores,
            httpClientMetrics: $this->metrics,
        );
    }

    private function onlySpan(): ImmutableSpan
    {
        $spans = $this->telemetry->spans();
        $this->assertCount(1, $spans, 'Expected exactly one exported span: ' . implode(', ', $this->telemetry->spanNames()));

        return $spans[0];
    }

    private function sentHeader(string $name): ?string
    {
        /** @var array<string, list<string>> $headers */
        $headers = $this->capturedOptions['normalized_headers'] ?? [];
        $line = $headers[mb_strtolower($name)][0] ?? null;

        return $line === null ? null : mb_trim(explode(':', $line, 2)[1]);
    }

    public function testSuccessfulRequestCreatesClientSpan(): void
    {
        $client = $this->createClient(new MockResponse('ok', ['http_code' => 200]));

        $response = $client->request('GET', 'https://api.example.com:8443/users?page=2');
        $this->assertSame('ok', $response->getContent());

        $span = $this->onlySpan();
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('GET', $span->getName(), 'Semantic conventions: client span name is the method only');
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(HttpTracingMiddleware::INSTRUMENTATION_NAME, $span->getInstrumentationScope()->getName());
        $this->assertSame('GET', $attributes['http.request.method']);
        $this->assertSame('https://api.example.com:8443/users?page=2', $attributes['url.full']);
        $this->assertSame('api.example.com', $attributes['server.address']);
        $this->assertSame(8_443, $attributes['server.port']);
        $this->assertSame(200, $attributes['http.response.status_code']);
        $this->assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertTrue($span->hasEnded());

        $this->assertCount(1, $this->metrics->requests);
        $this->assertCount(0, $this->metrics->errors);
    }

    public function testDefaultPortIsResolvedFromScheme(): void
    {
        $client = $this->createClient(new MockResponse('ok'));
        $client->request('GET', 'http://example.com/')->getContent();

        $this->assertSame(80, $this->onlySpan()->getAttributes()->get('server.port'));
    }

    public function testTraceContextInjected(): void
    {
        $client = $this->createClient(new MockResponse('ok'));
        $client->request('POST', 'https://example.com/', ['headers' => ['X-Custom' => 'value']])->getContent();

        $span = $this->onlySpan();
        $traceparent = $this->sentHeader('traceparent');

        $this->assertSame(
            sprintf('00-%s-%s-%02x', $span->getTraceId(), $span->getSpanId(), $span->getContext()->getTraceFlags()),
            $traceparent,
            'Downstream service must receive the client span as parent'
        );
        $this->assertSame('value', $this->sentHeader('X-Custom'), 'User headers must be preserved');
    }

    public function testTraceContextInjectedListHeaders(): void
    {
        $client = $this->createClient(new MockResponse('ok'));
        $client->request('GET', 'https://example.com/', ['headers' => ['Accept: application/json']])->getContent();

        $this->assertNotNull($this->sentHeader('traceparent'));
        $this->assertSame('application/json', $this->sentHeader('Accept'));
    }

    public function testClientSpanIsChildOfCurrentSpan(): void
    {
        $parent = $this->telemetry->tracerProvider->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $this->createClient(new MockResponse('ok'))->request('GET', 'https://example.com/')->getContent();
        } finally {
            $scope->detach();
            $parent->end();
        }

        $client = $this->telemetry->span('GET');
        $this->assertSame($parent->getContext()->getTraceId(), $client->getTraceId());
        $this->assertSame($parent->getContext()->getSpanId(), $client->getParentSpanId());
    }

    public function testSensitiveDataIsRedactedFromUrl(): void
    {
        $client = $this->createClient(new MockResponse('ok'));
        $client->request('GET', 'https://user:pass@example.com/reset?token=secret123&lang=en&api_key=k')->getContent();

        $span = $this->onlySpan();
        $urlFull = $span->getAttributes()->get('url.full');

        $this->assertSame('https://REDACTED:REDACTED@example.com/reset?token=REDACTED&lang=en&api_key=REDACTED', $urlFull);
        $this->assertStringNotContainsString('secret123', $span->getName());
    }

    public function testServerErrorMarksSpanAsError(): void
    {
        $client = $this->createClient(new MockResponse('boom', ['http_code' => 503]));
        $response = $client->request('GET', 'https://example.com/');

        $this->assertSame(503, $response->getStatusCode());
        $response->getContent(false);

        $span = $this->onlySpan();
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('503', $span->getAttributes()->get('error.type'));
        $this->assertSame(503, $span->getAttributes()->get('http.response.status_code'));
    }

    public function testClientErrorIsNotASpanError(): void
    {
        $client = $this->createClient(new MockResponse('nope', ['http_code' => 404]));
        $client->request('GET', 'https://example.com/')->getContent(false);

        $span = $this->onlySpan();
        $this->assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(404, $span->getAttributes()->get('http.response.status_code'));
    }

    public function testTransportErrorEndsSpanWithError(): void
    {
        $client = $this->createClient(new MockResponse('', ['error' => 'Connection refused']));
        $response = $client->request('GET', 'https://down.example.com/');

        try {
            $response->getStatusCode();
            $this->fail('A transport exception was expected');
        } catch (TransportException) {
        }

        $span = $this->onlySpan();
        $this->assertTrue($span->hasEnded(), 'Span must be ended on transport errors (timeouts, DNS, refused)');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(TransportException::class, $span->getAttributes()->get('error.type'));
        $this->assertCount(1, $span->getEvents(), 'Exception event expected');
        $this->assertSame('exception', $span->getEvents()[0]->getName());

        $this->assertCount(1, $this->metrics->errors);
        $this->assertCount(0, $this->metrics->requests);
    }

    public function testExceptionThrownByInnerClientEndsSpan(): void
    {
        $inner = $this->createStub(HttpClientInterface::class);
        $inner->method('request')->willThrowException(new RuntimeException('invalid option'));

        $client = new HttpTracingMiddleware(
            client: $inner,
            instrumentation: ScopedInstrumentation::create(HttpTracingMiddleware::INSTRUMENTATION_NAME),
            httpClientMetrics: $this->metrics,
        );

        try {
            $client->request('GET', 'https://example.com/');
            $this->fail('Exception expected');
        } catch (RuntimeException $exception) {
            $this->assertSame('invalid option', $exception->getMessage());
        }

        $span = $this->onlySpan();
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertCount(1, $this->metrics->errors);
    }

    public function testConcurrentRequestsSpans(): void
    {
        $client = $this->createClient(static fn (): MockResponse => new MockResponse('ok'));

        /** @var list<ResponseInterface> $responses */
        $responses = [];
        foreach (['a', 'b', 'c'] as $path) {
            $responses[] = $client->request('GET', 'https://example.com/' . $path);
        }

        foreach ($client->stream($responses) as $response => $chunk) {
            if ($chunk->isLast()) {
                $response->getContent();
            }
        }

        $spans = $this->telemetry->spans();
        $this->assertCount(3, $spans);
        $this->assertCount(3, array_unique(array_map(static fn (ImmutableSpan $s): string => $s->getSpanId(), $spans)));
        $this->assertCount(3, $this->metrics->requests);
    }

    public function testSpanNameHandlerCanCustomiseName(): void
    {
        $handler = new class() implements HttpClientSpanNameHandlerInterface {
            public function process(string $spanName, string $method, string $url, array $options): string
            {
                return $spanName . ' ' . parse_url($url, PHP_URL_PATH);
            }
        };

        $this->createClient(new MockResponse('ok'), nameHandlers: [$handler])
            ->request('GET', 'https://example.com/health')
            ->getContent();

        $this->assertSame('GET /health', $this->onlySpan()->getName());
    }

    public function testIgnoredRequestNotTraced(): void
    {
        $ignore = new class() implements HttpClientTraceIgnoreInterface {
            public function shouldIgnore(string $spanName, string $method, string $url, array $options): bool
            {
                return str_contains($url, '/health');
            }
        };

        $response = $this->createClient(new MockResponse('ok'), ignores: [$ignore])
            ->request('GET', 'https://example.com/health');

        $this->assertSame('ok', $response->getContent());
        $this->assertCount(0, $this->telemetry->spans());
        $this->assertNull($this->sentHeader('traceparent'));
        $this->assertCount(0, $this->metrics->requests);
    }

    public function testAttributeProvidersEnrichSpan(): void
    {
        $provider = new class() implements HttpClientAttributeProviderInterface {
            public function provide(array $context): array
            {
                return ['peer.service' => 'billing', '' => 'ignored'];
            }
        };

        $this->createClient(new MockResponse('ok'), providers: [$provider])
            ->request('GET', 'https://example.com/')
            ->getContent();

        $attributes = $this->onlySpan()->getAttributes()->toArray();
        $this->assertSame('billing', $attributes['peer.service']);
        $this->assertArrayNotHasKey('', $attributes);
    }

    public function testTracingDisabledPropagatesParent(): void
    {
        $parent = $this->telemetry->tracerProvider->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $this->createClient(new MockResponse('ok'), tracingEnabled: false)
                ->request('GET', 'https://example.com/')
                ->getContent();
        } finally {
            $scope->detach();
            $parent->end();
        }

        $this->assertSame(['parent'], $this->telemetry->spanNames(), 'No client span when tracing is disabled');
        $this->assertSame(
            sprintf('00-%s-%s-%02x', $parent->getContext()->getTraceId(), $parent->getContext()->getSpanId(), $parent->getContext()->getTraceFlags()),
            $this->sentHeader('traceparent'),
            'Trace context of the parent must still be propagated'
        );
        $this->assertCount(1, $this->metrics->requests, 'Metrics keep working when tracing is disabled');
    }
}
