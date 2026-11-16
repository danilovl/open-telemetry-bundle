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
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator\HeaderBagPropagationGetter;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
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
    ControllerEvent,
    ExceptionEvent,
    RequestEvent,
    ResponseEvent,
    TerminateEvent
};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use WeakMap;

final class HttpRequestTracingSubscriber implements EventSubscriberInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.http_server';

    /** Route template for HttpServerMetricsInterface implementations. */
    public const string ROUTE_TEMPLATE_ATTRIBUTE = '_danilovl_otel_http_route';

    /**
     * @var WeakMap<Request, RequestTracingContext>
     */
    private WeakMap $contextMap;

    /**
     * @param iterable<HttpRequestAttributeProviderInterface> $requestAttributeProviders
     * @param iterable<HttpRequestSpanNameHandlerInterface> $httpRequestSpanNameHandlers
     * @param iterable<HttpRequestTraceIgnoreInterface> $httpRequestTraceIgnores
     * @param list<string> $sensitiveQueryParameters
     */
    public function __construct(
        private readonly ScopedInstrumentation $instrumentation,
        private readonly ?RouterInterface $router = null,
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_ATTRIBUTE_PROVIDER)]
        private readonly iterable $requestAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_SPAN_NAME_HANDLER)]
        private readonly iterable $httpRequestSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::HTTP_REQUEST_TRACE_IGNORE)]
        private readonly iterable $httpRequestTraceIgnores = [],
        private readonly ?HttpServerMetricsInterface $httpServerMetrics = null,
        private readonly array $sensitiveQueryParameters = UrlHelper::DEFAULT_SENSITIVE_QUERY_PARAMETERS,
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

        $this->contextMap = new WeakMap;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 64],
            KernelEvents::CONTROLLER => ['onController', 100],
            KernelEvents::EXCEPTION => ['onException', -10],
            KernelEvents::RESPONSE => ['onResponse', -1_000],
            KernelEvents::TERMINATE => ['onTerminate', -2_000]
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getMethod();

        // no raw path: high cardinality, may contain secrets
        $spanName = $method;

        foreach ($this->httpRequestSpanNameHandlers as $httpRequestSpanNameHandler) {
            $spanName = $httpRequestSpanNameHandler->process($spanName, $event);
        }

        foreach ($this->httpRequestTraceIgnores as $httpRequestTraceIgnore) {
            if ($httpRequestTraceIgnore->shouldIgnore($spanName, $event)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameBuilder */
        $spanNameBuilder = $spanName === '' ? $method : $spanName;

        $protocolVersion = $request->getProtocolVersion();
        $protocolVersion = $protocolVersion !== null ? strtr($protocolVersion, ['HTTP/' => '']) : null;

        $parentContext = Globals::propagator()->extract(
            $request->headers,
            HeaderBagPropagationGetter::getInstance(),
            Context::getCurrent()
        );

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameBuilder)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute(UrlAttributes::URL_FULL, UrlHelper::sanitize($request->getUri(), $this->sensitiveQueryParameters))
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $this->resolveContentLength($request))
            ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
            ->setAttribute(UrlAttributes::URL_PATH, $request->getPathInfo())
            ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getHost())
            ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
            ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $protocolVersion)
            ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->getClientIp())
            ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->server->get('REMOTE_ADDR'))
            ->setAttribute(NetworkAttributes::NETWORK_PEER_PORT, $this->resolvePeerPort($request))
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->requestAttributeProviders,
            context: ['request' => $request, 'event' => $event]
        );

        $scope = Context::storage()->attach($span->storeInContext($parentContext));

        $this->contextMap[$request] = new RequestTracingContext(
            span: $span,
            scope: $scope,
            startTime: hrtime(true)
        );
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $context = $this->contextMap[$request] ?? null;

        if (!$context instanceof RequestTracingContext) {
            return;
        }

        $routeName = $request->attributes->get('_route');
        $routeTemplate = is_string($routeName) && $routeName !== ''
            ? $this->router?->getRouteCollection()->get($routeName)?->getPath()
            : null;

        if (is_string($routeName) && $routeName !== '') {
            $context->span->setAttribute('symfony.route.name', $routeName);
        }

        if (!is_string($routeTemplate) || $routeTemplate === '') {
            return;
        }

        $request->attributes->set(self::ROUTE_TEMPLATE_ATTRIBUTE, $routeTemplate);

        $context->span->updateName(sprintf('%s %s', $request->getMethod(), $routeTemplate));
        $context->span->setAttribute(HttpAttributes::HTTP_ROUTE, $routeTemplate);
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $context = $this->contextMap[$request] ?? null;

        if (!$context instanceof RequestTracingContext) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $context->exceptionRecorded = true;
        $context->span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
        $context->span->recordException($exception);
        $context->span->setStatus(
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
        $context = $this->contextMap[$request] ?? null;

        if (!$context instanceof RequestTracingContext) {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();
        $context->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        // keep the exception class set in onException()
        if ($statusCode >= 500 && !$context->exceptionRecorded) {
            $context->span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
            $context->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->recordRequestMetrics($request, $context, $statusCode);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $context = $this->contextMap[$request] ?? null;

        if (!$context instanceof RequestTracingContext) {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();
        $context->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

        // fallback when kernel.response was not dispatched
        $this->recordRequestMetrics($request, $context, $statusCode);

        $context->finish();

        unset($this->contextMap[$request]);
    }

    private function recordRequestMetrics(Request $request, RequestTracingContext $context, int $statusCode): void
    {
        if ($context->metricsRecorded) {
            return;
        }

        $context->metricsRecorded = true;
        $durationMs = (hrtime(true) - $context->startTime) / 1_000_000;

        $this->httpServerMetrics?->recordRequest($request, $statusCode, $durationMs);
    }

    private function resolveContentLength(Request $request): ?int
    {
        $contentLength = $request->headers->get('Content-Length');

        return is_numeric($contentLength) ? (int) $contentLength : null;
    }

    private function resolvePeerPort(Request $request): ?int
    {
        $port = $request->server->get('REMOTE_PORT');

        return is_numeric($port) ? (int) $port : null;
    }
}
