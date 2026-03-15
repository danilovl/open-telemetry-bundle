<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\{
    HttpRequestAttributeProviderInterface,
    HttpRequestSpanNameHandlerInterface,
    HttpRequestTraceIgnoreInterface,
    HttpServerMetricsInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\{
    SpanAttributeEnricher,
    UrlHelper
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\{
    Context,
    ContextStorageScopeInterface
};
use OpenTelemetry\SemConv\Attributes\{
    ClientAttributes,
    ErrorAttributes,
    HttpAttributes,
    NetworkAttributes,
    ServerAttributes,
    UrlAttributes,
    UserAgentAttributes
};
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\{
    ExceptionEvent,
    RequestEvent,
    ResponseEvent,
    TerminateEvent
};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

final class HttpRequestTracingSubscriber implements EventSubscriberInterface
{
    private const string SPAN_ATTRIBUTE = '_otel_http_span';
    private const string SCOPE_ATTRIBUTE = '_otel_http_scope';
    private const string START_TIME_ATTRIBUTE = '_otel_http_start_time';

    /**
     * @param iterable<HttpRequestAttributeProviderInterface> $requestAttributeProviders
     * @param iterable<HttpRequestSpanNameHandlerInterface> $httpRequestSpanNameHandlers
     * @param iterable<HttpRequestTraceIgnoreInterface> $httpRequestTraceIgnores
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        private readonly ?RouterInterface $router = null,
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_ATTRIBUTE_PROVIDER)]
        private readonly iterable $requestAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_SPAN_NAME_HANDLER)]
        private readonly iterable $httpRequestSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_TRACE_IGNORE)]
        private readonly iterable $httpRequestTraceIgnores = [],
        private readonly ?HttpServerMetricsInterface $httpServerMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$requestAttributeProviders',
            items: $this->requestAttributeProviders,
            expectedType: HttpRequestAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$httpRequestSpanNameHandlers',
            items: $this->httpRequestSpanNameHandlers,
            expectedType: HttpRequestSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$httpRequestTraceIgnores',
            items: $this->httpRequestTraceIgnores,
            expectedType: HttpRequestTraceIgnoreInterface::class
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 16],
            KernelEvents::EXCEPTION => ['onException', 0],
            KernelEvents::RESPONSE => ['onResponse', -1_000],
            KernelEvents::TERMINATE => ['onTerminate', -2_000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        $routePath = is_string($route) && $route !== ''
            ? $this->router?->getRouteCollection()->get($route)?->getPath()
            : null;

        $spanName = sprintf(
            '%s %s',
            $request->getMethod(),
            $routePath ?: $request->getPathInfo()
        );

        foreach ($this->httpRequestSpanNameHandlers as $httpRequestSpanNameHandler) {
            $spanName = $httpRequestSpanNameHandler->process($spanName, $event);
        }

        foreach ($this->httpRequestTraceIgnores as $httpRequestTraceIgnore) {
            if ($httpRequestTraceIgnore->shouldIgnore($spanName, $event)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameBuilder */
        $spanNameBuilder = $spanName === '' ? 'http server request' : $spanName;

        $protocolVersion = $request->getProtocolVersion();
        $protocolVersion = $protocolVersion !== null ? strtr($protocolVersion, ['HTTP/' => '']) : null;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameBuilder)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute(UrlAttributes::URL_FULL, UrlHelper::sanitize($request->getUri()))
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
            ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->headers->get('Content-Length'))
            ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
            ->setAttribute(UrlAttributes::URL_PATH, $request->getPathInfo())
            ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getHost())
            ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
            ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $protocolVersion)
            ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->getClientIp())
            ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->server->get('REMOTE_HOST'))
            ->setAttribute(ClientAttributes::CLIENT_PORT, $request->server->get('REMOTE_PORT'))
            ->setAttribute(HttpAttributes::HTTP_ROUTE, is_string($route) ? $route : 'unknown')
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->requestAttributeProviders,
            context: ['request' => $request, 'event' => $event]
        );

        $context = $span->storeInContext(Context::getCurrent());
        $scope = Context::storage()->attach($context);

        $request->attributes->set(self::SPAN_ATTRIBUTE, $span);
        $request->attributes->set(self::SCOPE_ATTRIBUTE, $scope);
        $request->attributes->set(self::START_TIME_ATTRIBUTE, hrtime(true));
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $span = $request->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
        $span->recordException($exception);
        $span->setStatus(
            code: StatusCode::STATUS_ERROR,
            description: $exception->getMessage()
        );

        $this->httpServerMetrics?->recordError($request, $exception);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $span = $request->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        if ($statusCode >= 500) {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->recordRequestMetrics($request, $response->getStatusCode());
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $span = $request->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $event->getResponse()->getStatusCode());

        $this->recordRequestMetrics($request, $event->getResponse()->getStatusCode());

        $this->finishSpan($request);
    }

    private function recordRequestMetrics(Request $request, int $statusCode): void
    {
        $startTime = $request->attributes->get(self::START_TIME_ATTRIBUTE);

        if (!is_numeric($startTime)) {
            return;
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->httpServerMetrics?->recordRequest($request, $statusCode, $durationMs);

        $request->attributes->remove(self::START_TIME_ATTRIBUTE);
    }

    private function finishSpan(Request $request): void
    {
        $span = $request->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $scope = $request->attributes->get(self::SCOPE_ATTRIBUTE);

        if ($scope instanceof ContextStorageScopeInterface) {
            $scope->detach();
        } else {
            Context::storage()->scope()?->detach();
        }

        $span->end();

        $request->attributes->remove(self::SPAN_ATTRIBUTE);
        $request->attributes->remove(self::SCOPE_ATTRIBUTE);
        $request->attributes->remove(self::START_TIME_ATTRIBUTE);
    }
}
