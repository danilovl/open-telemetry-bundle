<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Stamp\TraceContextStamp;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk\{
    InMemoryTracerProviderFactory,
    SdkMockResponseFactory,
    SdkTestKernel,
    SdkTestMessageHandler
};
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Cache\CacheInterface;

class HttpRequestLifecycleTest extends TestCase
{
    private static SdkTestKernel $kernel;

    private InMemoryTracerProviderFactory $tracing;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new SdkTestKernel;
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    protected function setUp(): void
    {
        /** @var InMemoryTracerProviderFactory $factory */
        $factory = self::$kernel->getContainer()->get(DefaultTracerProviderFactory::class);
        $this->tracing = $factory;
        $this->tracing->exporter->getStorage()->exchangeArray([]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(string $uri, array $headers = []): Response
    {
        $request = Request::create($uri);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function spans(): array
    {
        /** @var list<ImmutableSpan> $spans */
        $spans = array_values($this->tracing->exporter->getSpans());

        return $spans;
    }

    private function serverSpan(): ImmutableSpan
    {
        $servers = array_values(array_filter($this->spans(), static fn (ImmutableSpan $span): bool => $span->getKind() === SpanKind::KIND_SERVER));
        $this->assertCount(1, $servers, 'Exactly one server span expected');

        return $servers[0];
    }

    private function spanOfKind(int $kind): ImmutableSpan
    {
        $spans = array_values(array_filter($this->spans(), static fn (ImmutableSpan $span): bool => $span->getKind() === $kind));
        $this->assertCount(1, $spans);

        return $spans[0];
    }

    private function span(string $name): ImmutableSpan
    {
        $span = array_find($this->spans(), static fn (ImmutableSpan $span): bool => $span->getName() === $name);

        if ($span === null) {
            $names = array_map(static fn (ImmutableSpan $span): string => $span->getName(), $this->spans());

            throw new RuntimeException(sprintf('Span "%s" not found in [%s]', $name, implode(', ', $names)));
        }

        return $span;
    }

    public function testIncomingTraceparentIsContinued(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $parentSpanId = '00f067aa0ba902b7';

        $this->handle('/', ['traceparent' => sprintf('00-%s-%s-01', $traceId, $parentSpanId)]);

        $server = $this->serverSpan();
        $this->assertSame($traceId, $server->getTraceId(), 'Server span must join the caller trace');
        $this->assertSame($parentSpanId, $server->getParentSpanId());
        $this->assertTrue($server->getParentContext()->isRemote());
    }

    public function testIncomingBaggageAndTracestate(): void
    {
        $this->handle('/', [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'tracestate' => 'vendor=value',
        ]);

        $this->assertSame('vendor=value', (string) $this->serverSpan()->getContext()->getTraceState());
    }

    public function testInvalidTraceparentStartsNewTrace(): void
    {
        $this->handle('/', ['traceparent' => 'not-a-valid-header']);

        $server = $this->serverSpan();
        $this->assertSame('0000000000000000', $server->getParentSpanId());
        $this->assertTrue($server->getContext()->isValid());
    }

    public function testConsecutiveRequestsExported(): void
    {
        // the first request attaches the global SDK context
        $this->handle('/');
        $scopeAfterFirstRequest = Context::storage()->scope();

        $this->handle('/users/1');
        $this->handle('/users/2');

        $spans = $this->spans();
        $this->assertCount(3, $spans, 'Worker runtimes: telemetry after the first request must not be lost');
        $this->assertCount(3, array_unique(array_map(static fn (ImmutableSpan $s): string => $s->getTraceId(), $spans)), 'Each request is its own trace');
        $this->assertSame($scopeAfterFirstRequest, Context::storage()->scope(), 'No context scope may leak between requests');

        foreach ($spans as $span) {
            $this->assertSame('0000000000000000', $span->getParentSpanId(), 'A request must never become a child of the previous one');
        }
    }

    public function testSpanNameUsesRouteTemplate(): void
    {
        $this->handle('/users/42');

        $server = $this->serverSpan();
        $attributes = $server->getAttributes()->toArray();

        $this->assertSame('GET /users/{id}', $server->getName());
        $this->assertSame('/users/{id}', $attributes['http.route']);
        $this->assertSame('user_show', $attributes['symfony.route.name']);
        $this->assertSame('/users/42', $attributes['url.path']);
        $this->assertSame(200, $attributes['http.response.status_code']);
        $this->assertSame('sdk-test-app', $server->getResource()->getAttributes()->get('service.name'));
    }

    public function testUnmatchedRouteSpanNameHasNoPath(): void
    {
        $response = $this->handle('/wp-admin/secret-token-123');

        $server = $this->serverSpan();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('GET', $server->getName(), 'Unbounded raw paths must not end up in span names');
        $this->assertArrayNotHasKey('http.route', $server->getAttributes()->toArray());
        $this->assertNotSame(StatusCode::STATUS_ERROR, $server->getStatus()->getCode(), '4xx is not a server error');
    }

    public function testQueryStringSecretsAreRedacted(): void
    {
        $this->handle('/?token=abc123&page=2&api_key=xyz');

        $urlFull = $this->serverSpan()->getAttributes()->get('url.full');

        $this->assertIsString($urlFull);
        $this->assertStringContainsString('token=REDACTED', $urlFull);
        $this->assertStringContainsString('api_key=REDACTED', $urlFull);
        $this->assertStringContainsString('page=2', $urlFull);
        $this->assertStringNotContainsString('abc123', $urlFull);
    }

    public function testUnhandledExceptionIsError(): void
    {
        $response = $this->handle('/error');

        $server = $this->serverSpan();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(StatusCode::STATUS_ERROR, $server->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $server->getAttributes()->get('error.type'));
        $this->assertSame('exception', $server->getEvents()[0]->getName());
        $this->assertSame(500, $server->getAttributes()->get('http.response.status_code'));
    }

    public function testNotFoundExceptionIsNotAServerError(): void
    {
        $this->handle('/not-found');

        $server = $this->serverSpan();
        $this->assertNotSame(StatusCode::STATUS_ERROR, $server->getStatus()->getCode());
        $this->assertCount(0, $server->getEvents());
    }

    public function testServerErrorResponseIsError(): void
    {
        $this->handle('/unavailable');

        $server = $this->serverSpan();
        $this->assertSame(StatusCode::STATUS_ERROR, $server->getStatus()->getCode());
        $this->assertSame('503', $server->getAttributes()->get('error.type'));
    }

    public function testIgnoredPathCreatesNoSpan(): void
    {
        $this->handle('/_wdt/abc');

        $this->assertCount(0, $this->spans());
    }

    public function testCacheInterfaceIsTraced(): void
    {
        $cache = self::$kernel->getContainer()->get('cache.app');
        $this->assertInstanceOf(TracingCachePool::class, $cache);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        $this->assertSame('computed', $this->handle('/cache')->getContent());

        $cacheSpans = array_values(array_filter($this->spans(), static fn (ImmutableSpan $s): bool => $s->getName() === 'cache get'));
        $this->assertCount(2, $cacheSpans);
        $this->assertFalse($cacheSpans[0]->getAttributes()->get('cache.hit'));
        $this->assertTrue($cacheSpans[1]->getAttributes()->get('cache.hit'));

        $servers = array_values(array_filter($this->spans(), static fn (ImmutableSpan $s): bool => $s->getKind() === SpanKind::KIND_SERVER));
        $this->assertSame($servers[0]->getSpanId(), $cacheSpans[0]->getParentSpanId());
    }

    public function testOutgoingRequestPropagatesContext(): void
    {
        /** @var SdkMockResponseFactory $downstream */
        $downstream = self::$kernel->getContainer()->get(SdkMockResponseFactory::class);
        $downstream->reset();

        $this->handle('/outgoing');

        $server = $this->serverSpan();
        $client = $this->span('GET');

        $this->assertSame(SpanKind::KIND_CLIENT, $client->getKind());
        $this->assertSame($server->getSpanId(), $client->getParentSpanId());
        $this->assertSame('https://downstream.example.com/api?api_key=REDACTED', $client->getAttributes()->get('url.full'));
        $this->assertSame(200, $client->getAttributes()->get('http.response.status_code'));

        $this->assertCount(1, $downstream->requests, 'Exactly one downstream request (no double decoration)');
        $traceparent = $downstream->requests[0]['headers']['traceparent'][0] ?? '';
        $this->assertSame(
            sprintf('traceparent: 00-%s-%s-%02x', $client->getTraceId(), $client->getSpanId(), $client->getContext()->getTraceFlags()),
            $traceparent,
            'The downstream service must receive our client span as parent'
        );
    }

    public function testMessengerTraceContinuesInWorker(): void
    {
        $container = self::$kernel->getContainer();
        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $transport->reset();
        /** @var SdkTestMessageHandler $handler */
        $handler = $container->get(SdkTestMessageHandler::class);
        $handler->handled = [];

        $this->handle('/dispatch');

        $server = $this->serverSpan();
        $producer = $this->spanOfKind(SpanKind::KIND_PRODUCER);
        $this->assertSame('async', $producer->getAttributes()->get('messaging.destination.name'));
        $this->assertSame(SpanKind::KIND_PRODUCER, $producer->getKind());
        $this->assertSame($server->getSpanId(), $producer->getParentSpanId());

        $envelopes = iterator_to_array($transport->getSent());
        $this->assertCount(1, $envelopes);
        $this->assertNotNull($envelopes[0]->last(TraceContextStamp::class), 'Trace context must travel with the (serialized) message');
        $this->assertSame([], $handler->handled, 'Message is async: not handled during the request');

        // simulate messenger:consume
        $this->tracing->exporter->getStorage()->exchangeArray([]);
        /** @var MessageBusInterface $bus */
        $bus = $container->get('test.messenger.bus');
        foreach ($transport->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $transport->ack($envelope);
        }

        $consumer = $this->spanOfKind(SpanKind::KIND_CONSUMER);
        $this->assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
        $this->assertSame($producer->getTraceId(), $consumer->getTraceId(), 'Consumer must continue the HTTP request trace');
        $this->assertSame($producer->getSpanId(), $consumer->getParentSpanId());
        $this->assertSame([['payload' => 'from-http', 'traceId' => $producer->getTraceId()]], $handler->handled);
    }

    public function testTraceableControllerSpan(): void
    {
        $this->handle('/traceable');

        $server = $this->serverSpan();
        $controller = $this->span('traceable controller');

        $this->assertSame($server->getSpanId(), $controller->getParentSpanId());
        $this->assertTrue($controller->hasEnded());
        $this->assertLessThanOrEqual($server->getEndEpochNanos(), $controller->getEndEpochNanos());
    }
}
