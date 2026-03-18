<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\{
    HttpClientAttributeProviderInterface,
    HttpClientMetricsInterface,
    HttpClientSpanNameHandlerInterface,
    HttpClientTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\{
    Attribute\InstrumentationTags,
    Helper\SpanAttributeEnricher,
    Validator\AutowireIteratorTypeValidator
};
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\{
    ErrorAttributes,
    HttpAttributes
};
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\{
    AutowireIterator
};
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\{
    AsyncContext,
    AsyncResponse
};
use Symfony\Contracts\HttpClient\{
    ChunkInterface,
    HttpClientInterface,
    ResponseInterface,
};
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

final class HttpTracingMiddleware implements HttpClientInterface, ResetInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.http_client';

    use AsyncDecoratorTrait;

    /**
     * @param iterable<HttpClientAttributeProviderInterface> $httpClientAttributeProviders
     * @param iterable<HttpClientSpanNameHandlerInterface> $httpClientSpanNameHandlers
     * @param iterable<HttpClientTraceIgnoreInterface> $httpClientTraceIgnores
     */
    public function __construct(
        private HttpClientInterface $client,
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::HTTP_CLIENT_ATTRIBUTE_PROVIDER)]
        private readonly iterable $httpClientAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::HTTP_CLIENT_SPAN_NAME_HANDLER)]
        private readonly iterable $httpClientSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::HTTP_CLIENT_TRACE_IGNORE)]
        private readonly iterable $httpClientTraceIgnores = [],
        private readonly ?HttpClientMetricsInterface $httpClientMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$httpClientAttributeProviders',
            items: $this->httpClientAttributeProviders,
            expectedType: HttpClientAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$httpClientSpanNameHandlers',
            items: $this->httpClientSpanNameHandlers,
            expectedType: HttpClientSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$httpClientTraceIgnores',
            items: $this->httpClientTraceIgnores,
            expectedType: HttpClientTraceIgnoreInterface::class
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $spanName = sprintf('%s %s', $method, $url);

        $startTime = hrtime(true);

        foreach ($this->httpClientSpanNameHandlers as $httpClientSpanNameHandler) {
            $spanName = $httpClientSpanNameHandler->process($spanName, $method, $url, $options);
        }

        foreach ($this->httpClientTraceIgnores as $httpClientTraceIgnore) {
            if ($httpClientTraceIgnore->shouldIgnore($spanName, $method, $url, $options)) {
                return $this->client->request($method, $url, $options);
            }
        }

        $spanNameBuilder = $spanName === '' ? 'unknown' : $spanName;

        $spanBuilder = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameBuilder)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute('url.full', $url);

        foreach (self::resolveDependencyAttributes($url) as $attributeName => $attributeValue) {
            $spanBuilder->setAttribute($attributeName, $attributeValue);
        }

        $span = $spanBuilder->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->httpClientAttributeProviders,
            context: ['method' => $method, 'url' => $url, 'options' => $options]
        );

        $context = $span->storeInContext(Context::getCurrent());
        $scope = $context->activate();

        $headers = $options['headers'] ?? [];
        Globals::propagator()->inject($headers, null, $context);
        $options['headers'] = $headers;

        $isEnded = false;
        $passthru = function (ChunkInterface $chunk, AsyncContext $context) use ($span, $startTime, $method, $url, $options, &$isEnded) {
            if ($isEnded) {
                yield $chunk;

                return;
            }

            $scope = $span->storeInContext(Context::getCurrent())->activate();

            try {
                $errorMessage = $chunk->getError();
                if ($errorMessage !== null) {
                    $e = new RuntimeException($errorMessage);
                    $durationMs = (hrtime(true) - $startTime) / 1_000_000;
                    $this->httpClientMetrics?->recordError($method, $url, $options, $e, $durationMs);

                    $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
                    $span->recordException($e);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($chunk->isLast()) {
                    $isEnded = true;
                    /** @var array<string, mixed> $info */
                    $info = $context->getInfo();
                    $statusCode = 0;
                    if (isset($info['http_code']) && is_scalar($info['http_code'])) {
                        $statusCode = (int) $info['http_code'];
                    }

                    $durationMs = (hrtime(true) - $startTime) / 1_000_000;

                    if ($statusCode !== 0 && $span->isRecording()) {
                        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

                        if ($statusCode >= 500) {
                            $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }

                        $this->httpClientMetrics?->recordRequest($method, $url, $options, $info, $durationMs);
                    }

                    $span->end();
                }
            } catch (Throwable $e) {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR);
            } finally {
                $scope->detach();
                yield $chunk;
            }
        };

        try {
            $response = new AsyncResponse($this->client, $method, $url, $options, $passthru);
            $scope->detach();

            return $response;
        } catch (Throwable $e) {
            if (!$isEnded) {
                $isEnded = true;
                $durationMs = (hrtime(true) - $startTime) / 1_000_000;
                $this->httpClientMetrics?->recordError($method, $url, $options, $e, $durationMs);

                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR);
                $span->end();
            }

            $scope->detach();

            throw $e;
        }
    }

    /**
     * @return array<string, string|int>
     */
    private static function resolveDependencyAttributes(string $url): array
    {
        $attributes = [];
        $parts = parse_url($url);

        if ($parts === false) {
            return $attributes;
        }

        /** @var array{host?: string, scheme?: string, port?: int|string} $parts */
        $host = $parts['host'] ?? '';
        if ($host !== '') {
            $attributes['server.address'] = $host;
        }

        $port = self::resolvePort($url, $parts);
        if ($port !== null) {
            $attributes['server.port'] = $port;
        }

        return $attributes;
    }

    /**
     * @param array{scheme?: string, port?: int|string}|false $parts
     */
    private static function resolvePort(string $url, array|false $parts): ?int
    {
        if ($parts === false) {
            return null;
        }

        $explicitPort = $parts['port'] ?? null;
        if ($explicitPort !== null) {
            return (int) $explicitPort;
        }

        $scheme = $parts['scheme'] ?? 'http';

        return match (mb_strtolower((string) $scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
