[![phpunit](https://github.com/danilovl/open-telemetry-bundle/actions/workflows/phpunit.yml/badge.svg)](https://github.com/danilovl/open-telemetry-bundle/actions/workflows/phpunit.yml)
[![downloads](https://img.shields.io/packagist/dt/danilovl/open-telemetry-bundle)](https://packagist.org/packages/danilovl/open-telemetry-bundle)
[![latest Stable Version](https://img.shields.io/packagist/v/danilovl/open-telemetry-bundle)](https://packagist.org/packages/danilovl/open-telemetry-bundle)
[![license](https://img.shields.io/packagist/l/danilovl/open-telemetry-bundle)](https://packagist.org/packages/danilovl/open-telemetry-bundle)

# OpenTelemetryBundle

`danilovl/open-telemetry-bundle` is a configurable Symfony bundle that integrates OpenTelemetry tracing and metrics into common Symfony and infrastructure flows.

You can:

- enable only the instrumentation you need
- replace any default implementation with your own
- configure each instrumentation independently
- extend spans with custom attributes via provider interfaces
- override span names and skip spans entirely via ignore interfaces

Kibana
------------

![Alt text](/readme/kibana.png?raw=true "Kibana")

Metrics
------------

![Alt text](/readme/metrics.png?raw=true "Metrics")

![Alt text](/readme/metrics-all.png?raw=true "Metrics all")


## Requirements

From `composer.json`:

- PHP `^8.5`
- `ext-opentelemetry: *`
- `symfony/framework-bundle: ^8.0`
- `open-telemetry/api: ^1.8`
- `open-telemetry/sdk: ^1.8`
- `open-telemetry/exporter-otlp: ^1.4`
- `open-telemetry/sem-conv: ^1.38`
- `nyholm/psr7: ^1.8`

## Installation

```bash
composer require danilovl/open-telemetry-bundle
```

If Symfony Flex does not register the bundle automatically, add it manually:

```php
// config/bundles.php
return [
    Danilovl\OpenTelemetryBundle\OpenTelemetryBundle::class => ['all' => true],
];
```

### Important: disable OpenTelemetry PHP extension auto-loading

The bundle initializes OpenTelemetry providers manually. If the PHP extension auto-loading is also active, it will conflict with the bundle initialization. You must disable it:

```bash
# .env or server environment
OTEL_PHP_AUTOLOAD_ENABLED=false
```

### Required environment variables

The bundle uses the OpenTelemetry SDK factories to create exporters, which read from standard OpenTelemetry environment variables:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_SERVICE_NAME=my-app
```

## Minimal configuration

Create `config/packages/open_telemetry.yaml`:

```yaml
danilovl_open_telemetry:
    service:
        name: 'my-app'
        environment: '%kernel.environment%'
    instrumentation:
        http_server:
            enabled: true
            tracing:
                enabled: true
```

## Full configuration example

```yaml
danilovl_open_telemetry:
    service:
        namespace: 'MyOrganization'         # maps to service.namespace OTEL resource attribute
        name: 'my-app'                      # maps to service.name OTEL resource attribute
        version: '1.0.0'                    # maps to service.version OTEL resource attribute
        environment: '%kernel.environment%' # maps to deployment.environment.name OTEL resource attribute

    instrumentation:
        http_server:
            enabled: true
            default_trace_ignore_enabled: true  # enables DefaultHttpRequestTraceIgnore
            tracing:
                enabled: true
            metering:
                enabled: false

        http_client:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        messenger:
            enabled: true
            long_running_command_enabled: true  # enables MessengerFlushSubscriber for messenger:consume
            tracing:
                enabled: true
            metering:
                enabled: false

        console:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        traceable:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        twig:
            enabled: true
            tracing:
                enabled: true

        cache:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: true

        doctrine:
            enabled: true
            default_trace_ignore_enabled: true   # enables DefaultDoctrineTraceIgnore
            default_span_name_handler_enabled: true # enables DefaultDoctrineSpanNameHandler
            tracing:
                enabled: true
            metering:
                enabled: false

        redis:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        predis:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        mailer:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false

        events:
            enabled: true
            default_trace_ignore_enabled: true   # enables DefaultEventTraceIgnore (ignores vendor events)
            default_span_name_handler_enabled: true # enables DefaultEventSpanNameHandler
            tracing:
                enabled: true
            metering:
                enabled: false

        async:
            enabled: true
            tracing:
                enabled: true
            metering:
                enabled: false
```

## Bundle initialization flow

This section describes how the bundle boots, how services are registered during container compilation,
and how user-defined implementations automatically override bundle defaults.

### Phase 1 — Container compilation (`OpenTelemetryExtension`)

When Symfony compiles the container it calls `OpenTelemetryExtension::load()`.
The extension performs the following steps in order:

```
OpenTelemetryExtension::load()
│
├── processConfiguration()          parse & validate open_telemetry.yaml
│
├── YamlFileLoader → services.yaml  register all base bundle services
│
├── DefaultResourceInfoFactory      inject service.name / namespace / version / environment
│   └── ResourceInfo definition     factory-backed: create() called at runtime
│
├── registerInstrumentationServices()
│   └── for each enabled instrumentation block (http_server, doctrine, redis …)
│       ├── check PHP extension / class dependencies
│       └── register instrumentation-specific services (subscribers, middleware, decorators)
│
├── registerCachedInstrumentation() wrap instrumentation with CachedInstrumentation if available
│
├── setInstrumentationMetricsArgument() (×N)
│   └── create lazy MetricsRecorder reference for each instrumentation
│
├── Provider registrations (factory-backed definitions)
│   ├── TracerProviderInterface   → DefaultTracerProviderFactory::create()
│   ├── MeterProviderInterface    → DefaultMeterProviderFactory::create()
│   └── LoggerProviderInterface   → DefaultLoggerProviderFactory::create()
│
├── ContextStorageInterface       → Context::storage()
├── TracingSpanServiceInterface   → TracingSpanService  (autowired)
│
└── registerProviderAutoconfiguration()
    ├── TraceSpanProcessorInterface  → tag: otel.span_processor
    ├── TraceSpanExporterInterface   → tag: otel.span_exporter
    ├── LogRecordProcessorInterface  → tag: otel.log_processor
    ├── LogRecordExporterInterface   → tag: otel.log_exporter
    ├── MetricExporterInterface      → tag: otel.metric_exporter
    └── MetricReaderInterface        → tag: otel.metric_reader
```

### Phase 2 — Container compilation (`OpenTelemetryCompilerPass`)

After all extensions have loaded, Symfony runs compiler passes.
`OpenTelemetryCompilerPass::process()` wires the fully-built container:

```
OpenTelemetryCompilerPass::process()
│
├── registerMessengerMiddleware()
│   ├── inject MessageBusTracingMiddleware into messenger.bus.default (parameter-based)
│   └── inject into every service tagged messenger.bus (IteratorArgument or array)
│
├── registerHttpClientDecorator()
│   └── clone HttpTracingMiddleware for each http_client.client tagged service
│       └── setDecoratedService($id, priority: 1000)
│
├── registerEventDispatcherDecorator()
│   └── TracingEventDispatcher decorates event_dispatcher
│
├── registerCacheDecorator()
│   └── TracingCachePool decorates cache.app
│
├── registerRedisDecorator()
│   └── scan all definitions → find services extending Redis
│       └── clone TracingPhpRedis, setDecoratedService($id)
│
├── registerPRedisDecorator()
│   └── scan all definitions → find services implementing Predis\ClientInterface
│       └── clone TracingRedis, setDecoratedService($id)
│
├── overrideInterfaceAliasesByUserImplementations()  ← see Phase 2a below
│
├── registerTracerProviderProcessors()
│   ├── collect services tagged otel.span_processor  → setArgument('$processors')
│   └── collect services tagged otel.span_exporter   → setArgument('$exporters')
│
├── registerLoggerProviderProcessors()
│   ├── collect services tagged otel.log_processor   → setArgument('$processors')
│   └── collect services tagged otel.log_exporter    → setArgument('$exporters')
│
└── registerMeterProviderReaders()
    ├── collect services tagged otel.metric_exporter → setArgument('$exporters')
    └── collect services tagged otel.metric_reader   → setArgument('$readers')
```

#### Phase 2.1 — Automatic interface alias override

For each instrumentation metrics interface the bundle registers a default alias:

```
Interface                        Default alias (bundle service)
──────────────────────────────────────────────────────────────────────
HttpServerMetricsInterface    →  danilovl.open_telemetry.metrics.http_server.default
DoctrineMetricsInterface      →  danilovl.open_telemetry.metrics.doctrine.default
RedisMetricsInterface         →  danilovl.open_telemetry.metrics.redis.default
CacheMetricsInterface         →  danilovl.open_telemetry.metrics.cache.default
ConsoleMetricsInterface       →  danilovl.open_telemetry.metrics.console.default
MessengerMetricsInterface     →  danilovl.open_telemetry.metrics.messenger.default
AsyncMetricsInterface         →  danilovl.open_telemetry.metrics.async.default
HttpClientMetricsInterface    →  danilovl.open_telemetry.metrics.http_client.default
MailerMetricsInterface        →  danilovl.open_telemetry.metrics.mailer.default
EventDispatcherMetricsInterface → danilovl.open_telemetry.metrics.events.default
TraceableMetricsInterface     →  danilovl.open_telemetry.metrics.traceable.default
```

At compile time `overrideInterfaceAliasesByUserImplementations()` scans every container
definition. If it finds exactly one user-defined service that implements an interface,
it automatically re-points the alias to that service — no explicit YAML configuration needed.
If more than one custom implementation is found, a `LogicException` is thrown asking the
developer to declare the alias explicitly.

```
User registers MyDoctrineMetrics implements DoctrineMetricsInterface
        │
        ▼
CompilerPass detects 1 custom implementation
        │
        ▼
setAlias(DoctrineMetricsInterface, MyDoctrineMetrics)
        │
        ▼
All instrumentation services now inject MyDoctrineMetrics automatically
```

The same mechanism applies to the provider factories via aliases set in the extension:

```yaml
# No YAML needed — just implement the interface and register the service
services:
    App\Tracing\MyTracerProviderFactory:
        # implements TracerProviderFactoryInterface
        # CompilerPass detects it and overrides the TracerProviderFactoryInterface alias
```

#### Phase 2.2 — `TraceableHookCompilerPass`

A second compiler pass registered in `OpenTelemetryBundle::build()` at `PassConfig::TYPE_BEFORE_REMOVING` priority.
This ensures all Symfony autoconfiguration tags (`controller.service_arguments`, `routing.controller`) are already
applied before the pass runs, making controller/command detection reliable.

It scans every container definition for classes that carry the `#[Traceable]` attribute
(at class or method level) and builds a static hook map for `TraceableHookSubscriber`:

```
TraceableHookCompilerPass::process()  [runs at TYPE_BEFORE_REMOVING — all DI tags are resolved]
│
├── iterate all container definitions
│   ├── skip abstract / synthetic / placeholder definitions
│   └── reflect each concrete class with #[Traceable] on class or any method
│       ├── handler = CONTROLLER or COMMAND → skip (TraceableSubscriber handles it)
│       └── handler = null (auto-detect):
│           ├── is_a Command → skip
│           ├── hasTag('controller.service_arguments') → skip
│           ├── hasTag('routing.controller') → skip
│           └── otherwise → register hook
│
├── deduplicate hooks by key "ClassName::methodName"
│   └── only one entry per method survives (method-level wins over class-level)
│
└── setArgument('$hooks', $hooks) → TraceableHookSubscriber
        └── subscriber wraps every listed method with a tracing span at runtime
```

### Phase 3 — Runtime SDK initialization (`OpenTelemetryInitializer`)

The OpenTelemetry SDK is **not** initialized during container compilation.
It is initialized lazily on the first handled event:

```
HTTP request arrives
        │
        ▼
KernelEvents::REQUEST (priority PHP_INT_MAX — runs first)
        │
        ▼
OpenTelemetryInitializer::onKernelRequest()
        │
        ├── already initialized? → skip
        │
        └── OpenTelemetryFactory::initializeSdk()
                ├── build ResourceInfo  (service.name, namespace, version, environment)
                ├── TracerProviderFactory::create()   → SDK TracerProvider
                ├── MeterProviderFactory::create()    → SDK MeterProvider
                ├── LoggerProviderFactory::create()   → SDK LoggerProvider
                ├── PropagatorFactory::create()       → TextMapPropagator
                └── register providers in OpenTelemetry Globals
                        └── all instrumentation services now emit telemetry

Console command (CLI)
        │
        ▼
ConsoleEvents::COMMAND (priority PHP_INT_MAX)
        └── same OpenTelemetryInitializer::initialize() path
```

### Phase 4 — SDK shutdown (`TracerShutdownSubscriber`)

After the HTTP response is sent or the console command finishes, the SDK must be
flushed and shut down to ensure all buffered spans are exported before the process ends:

```
HTTP cycle ends
        │
        ▼
KernelEvents::TERMINATE (priority -PHP_INT_MAX + 2 — runs last)
        │
        ▼
TracerShutdownSubscriber::onTerminate()
        └── Globals::tracerProvider() instanceof TracerProviderInterface?
                └── yes → TracerProvider::shutdown()
                        └── flushes BatchSpanProcessor, closes exporter connections

Console command ends
        │
        ▼
ConsoleEvents::TERMINATE (priority -PHP_INT_MAX + 2)
        └── same TracerShutdownSubscriber::onTerminate() path
```

## Service configuration

The `service` block maps to OpenTelemetry resource attributes attached to all spans and metrics:

| Key | OTEL attribute | Description |
|-----|----------------|-------------|
| `namespace` | `service.namespace` | Logical grouping of services |
| `name` | `service.name` | Service identifier |
| `version` | `service.version` | Deployed version |
| `environment` | `deployment.environment.name` | e.g. `prod`, `dev` |

## Tracing setup

The bundle creates `TracerProvider`, `MeterProvider`, and `LoggerProvider` using factory classes that delegate to the OpenTelemetry SDK.

### DefaultTracerProviderFactory

Creates a `TracerProvider` with:

- `SpanExporterFactory` (reads `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_PROTOCOL`)
- `BatchSpanProcessor` with `SystemClock`
- `ParentBased(AlwaysOnSampler)` sampler

### DefaultMeterProviderFactory

Creates a `MeterProvider` with:

- `MetricExporterFactory` (reads `OTEL_EXPORTER_OTLP_ENDPOINT`)
- `ExportingReader`

### Replacing the provider factory

Implement `TracerProviderFactoryInterface` or `MeterProviderFactoryInterface` and register your class as a service. The container will use your implementation instead of the default.

```php
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Interfaces\TracerProviderFactoryInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

class MyTracerProviderFactory implements TracerProviderFactoryInterface
{
    public function create(iterable $processors = []): TracerProviderInterface
    {
        // build and return your TracerProvider
    }
}
```

---

## Custom Processors and Exporters

The bundle allows you to easily register custom OpenTelemetry processors and exporters. By implementing the provided interfaces, your services will be automatically discovered and injected into the corresponding providers via Symfony's DI autoconfiguration.

### Supported interfaces

#### Tracing
- `TraceSpanProcessorInterface`: For custom span processors. For convenience, you can extend `AbstractFilteringSpanProcessor`.
- `TraceSpanExporterInterface`: For custom span exporters. Automatically wrapped in a `BatchSpanProcessor`.

#### Logging
- `LogRecordProcessorInterface`: For custom log record processors. For convenience, you can extend `AbstractFilteringLogRecordProcessor`.
- `LogRecordExporterInterface`: For custom log record exporters. Automatically wrapped in a `SimpleLogRecordProcessor`.

#### Metrics
- `MetricExporterInterface`: For custom metric exporters. Automatically wrapped in an `ExportingReader`.
- `MetricReaderInterface`: For custom metric readers.

### Instrumentation scope filtering

Each interface includes a `getSupportedInstrumentation()` method. This allows you to restrict which instrumentation scopes the processor or exporter handles.

- Return an **empty array** to receive data from all scopes.
- Return an array of scope names (e.g. `['danilovl/open-telemetry/doctrine']`) to receive data only from specific instrumentations.

### Priority

The `getPriority()` method controls the order in which processors or readers are added to the provider. Higher values mean earlier registration.

---

## Cached Instrumentation

The bundle registers `CachedInstrumentation` services for all its components. This simplifies creating spans and metrics in your own services.

### Injecting CachedInstrumentation

You can inject the `OpenTelemetry\API\Instrumentation\CachedInstrumentation` service. By default, it uses the `danilovl/open-telemetry` instrumentation scope.

```php
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

class MyService
{
    public function __construct(
        private CachedInstrumentation $cachedInstrumentation
    ) {}

    public function doSomething(): void
    {
        $span = $this->cachedInstrumentation->tracer()->spanBuilder('my_action')->startSpan();
        // ...
        $span->end();
    }
}
```

### Component-specific instrumentation

The bundle also provides specific `CachedInstrumentation` services for each component. You can inject them by their service IDs:

- `danilovl.open_telemetry.instrumentation.async`
- `danilovl.open_telemetry.instrumentation.cache`
- `danilovl.open_telemetry.instrumentation.console`
- `danilovl.open_telemetry.instrumentation.doctrine`
- `danilovl.open_telemetry.instrumentation.events`
- `danilovl.open_telemetry.instrumentation.http_client`
- `danilovl.open_telemetry.instrumentation.http_server`
- `danilovl.open_telemetry.instrumentation.mailer`
- `danilovl.open_telemetry.instrumentation.messenger`
- `danilovl.open_telemetry.instrumentation.redis`
- `danilovl.open_telemetry.instrumentation.traceable`
- `danilovl.open_telemetry.instrumentation.twig`

---

## Instrumentation: http_server

### What it does

Listens to `KernelEvents::REQUEST`, `KernelEvents::EXCEPTION`, `KernelEvents::RESPONSE`, `KernelEvents::TERMINATE`. Creates one `SERVER` span per HTTP request.

### Configuration

```yaml
instrumentation:
    http_server:
        enabled: true
        default_trace_ignore_enabled: true   # registers DefaultHttpRequestTraceIgnore
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `url.full` | sanitized full URL |
| `http.request.method` | HTTP method |
| `http.request.body.size` | `Content-Length` header |
| `url.scheme` | request scheme |
| `url.path` | path info |
| `user_agent.original` | `User-Agent` header |
| `server.address` | host |
| `server.port` | port |
| `network.protocol.version` | HTTP version (e.g. `1.1`) |
| `network.peer.address` | client IP |
| `client.address` | `REMOTE_HOST` |
| `client.port` | `REMOTE_PORT` |
| `http.route` | matched route name |
| `http.response.status_code` | response status |
| `error.type` | exception class or status code on error |

### Interfaces

#### `HttpRequestAttributeProviderInterface`

Adds custom attributes to the request span.

Service tag: `danilovl.open_telemetry.http_request.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpRequestAttributeProviderInterface;

class MyHttpRequestAttributeProvider implements HttpRequestAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['request'] is Symfony\Component\HttpFoundation\Request
        // $context['event'] is RequestEvent
        return [
            'app.tenant' => $context['request']->headers->get('X-Tenant-Id'),
        ];
    }
}
```

#### `HttpRequestSpanNameHandlerInterface`

Overrides the span name.

Service tag: `danilovl.open_telemetry.http_request.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpRequestSpanNameHandlerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class MyHttpRequestSpanNameHandler implements HttpRequestSpanNameHandlerInterface
{
    public function process(string $spanName, RequestEvent $event): string
    {
        return 'custom ' . $spanName;
    }
}
```

#### `HttpRequestTraceIgnoreInterface`

Skips tracing for matching requests.

Service tag: `danilovl.open_telemetry.http_request.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpRequestTraceIgnoreInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class MyHttpRequestTraceIgnore implements HttpRequestTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, RequestEvent $event): bool
    {
        return str_starts_with($event->getRequest()->getPathInfo(), '/health');
    }
}
```

#### `HttpServerMetricsInterface`

Records metrics for HTTP server requests.

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Symfony\Component\HttpFoundation\Request;

class MyHttpServerMetrics implements HttpServerMetricsInterface
{
    public function recordRequest(Request $request, int $statusCode, float $durationMs): void { }
    public function recordError(Request $request, Throwable $exception): void { }
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultHttpRequestTraceIgnore` | Ignores requests to `/_wdt/` (Symfony web debug toolbar) |
| `DefaultHttpServerMetrics` | Records `http.server.requests_total`, `http.server.duration_ms`, `http.server.memory_usage`, `http.server.errors_total` |

---

## Instrumentation: http_client

### What it does

Decorates `HttpClientInterface` (priority 1000) using `AsyncDecoratorTrait`. Creates one `CLIENT` span per outgoing HTTP request. Injects trace context headers into the request automatically.

### Configuration

```yaml
instrumentation:
    http_client:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `http.request.method` | HTTP method |
| `url.full` | request URL |
| `server.address` | hostname from URL |
| `server.port` | port (or 80/443 by scheme) |
| `http.response.status_code` | response status |
| `error.type` | exception class or status code on error |

### Interfaces

#### `HttpClientAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.http_client.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientAttributeProviderInterface;

class MyHttpClientAttributeProvider implements HttpClientAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['method'], $context['url'], $context['options']
        return ['app.service' => 'payment-api'];
    }
}
```

#### `HttpClientSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.http_client.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientSpanNameHandlerInterface;

class MyHttpClientSpanNameHandler implements HttpClientSpanNameHandlerInterface
{
    public function process(string $spanName, string $method, string $url, array $options): string
    {
        return $spanName;
    }
}
```

#### `HttpClientTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.http_client.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\Interfaces\HttpClientTraceIgnoreInterface;

class MyHttpClientTraceIgnore implements HttpClientTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, string $method, string $url, array $options): bool
    {
        return str_contains($url, 'internal-health-check');
    }
}
```

#### `HttpClientMetricsInterface`

```php
interface HttpClientMetricsInterface
{
    public function recordRequest(string $method, string $url, array $options, array $info, float $durationMs): void;
    public function recordError(string $method, string $url, array $options, Throwable $exception, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultHttpClientMetrics` | Records `http.client.requests_total`, `http.client.duration_ms`, `http.client.memory_usage`, `http.client.errors_total` |

---

## Instrumentation: doctrine

### What it does

Registers a Doctrine DBAL middleware (`doctrine.middleware` tag). Wraps the Driver with `TraceableDriver` → `TraceableConnection` / `TraceableStatement`. Creates one `CLIENT` span per SQL operation.

### Configuration

```yaml
instrumentation:
    doctrine:
        enabled: true
        default_trace_ignore_enabled: true    # registers DefaultDoctrineTraceIgnore
        default_span_name_handler_enabled: true # registers DefaultDoctrineSpanNameHandler
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `db.system.name` | database system (e.g. `mysql`, `postgresql`) |
| `db.operation.name` | SQL operation (e.g. `SELECT`, `INSERT`, `BEGIN`, `COMMIT`) |
| `db.query.text` | full SQL query |
| `error.type` | exception class on error |

### Interfaces

#### `DoctrineSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.doctrine.span_name_handler`

Context keys available: `db.operation`, `db.system`, `db.name`, `db.sql`, `db.params`, `db.user`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineSpanNameHandlerInterface;

class MyDoctrineSpanNameHandler implements DoctrineSpanNameHandlerInterface
{
    public function process(string $spanName, array $context): string
    {
        return $spanName;
    }
}
```

#### `DoctrineTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.doctrine.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineTraceIgnoreInterface;

class MyDoctrineTraceIgnore implements DoctrineTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, array $context): bool
    {
        return str_contains((string) ($context['db.sql'] ?? ''), 'migration_versions');
    }
}
```

#### `DoctrineMetricsInterface`

```php
interface DoctrineMetricsInterface
{
    public function recordCall(string $dbSystem, string $operation, float $durationMs): void;
    public function recordError(string $dbSystem, string $operation, Throwable $exception, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultDoctrineSpanNameHandler` | Builds span name from SQL using `SqlHelper::buildSpanName()` |
| `DefaultDoctrineTraceIgnore` | Ignores: `db.connection/begin/prepare/commit/rollback`, system databases (`information_schema`, `mysql`, `pg_catalog`, etc.), schema queries, and SQL not referencing ORM-managed tables |
| `DefaultDoctrineMetrics` | Records `db.client.requests_total`, `db.client.duration_ms`, `db.client.memory_usage`, `db.client.errors_total` |

---

## Instrumentation: redis

### What it does

Automatically decorates all services implementing native PHP `Redis` class. Creates one `CLIENT` span per Redis command.

Traced commands: `GET`, `SET`, `SETEX`, `DEL`, `UNLINK`, `EXPIRE`, and any `__call` passthrough.

### Configuration

```yaml
instrumentation:
    redis:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `db.system.name` | `redis` |
| `db.operation.name` | command name (e.g. `GET`, `SET`) |
| `db.redis.key` | cache key |
| `error.type` | exception class on error |

### Interfaces

#### `RedisSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.redis.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisSpanNameHandlerInterface;

class MyRedisSpanNameHandler implements RedisSpanNameHandlerInterface
{
    public function process(string $spanName, string $command, string $key): string
    {
        return sprintf('redis.%s %s', strtolower($command), $key);
    }
}
```

#### `RedisTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.redis.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisTraceIgnoreInterface;

class MyRedisTraceIgnore implements RedisTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, string $command, string $key): bool
    {
        return $command === 'PING';
    }
}
```

#### `RedisAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.redis.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisAttributeProviderInterface;

class MyRedisAttributeProvider implements RedisAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['command'], $context['key']
        return ['app.cache.prefix' => 'session'];
    }
}
```

#### `RedisMetricsInterface`

```php
interface RedisMetricsInterface
{
    public function recordCommand(string $command, float $durationMs): void;
    public function recordError(string $command, Throwable $exception, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultRedisMetrics` | Records `redis.client.requests_total`, `redis.client.duration_ms`, `redis.client.memory_usage`, `redis.client.errors_total`; `db.system` attribute is set to `redis` |

---

## Instrumentation: predis

### What it does

Automatically decorates all services implementing `Predis\ClientInterface`. Creates one `CLIENT` span per Redis command.

Traced commands: `GET`, `SET`, `SETEX`, `DEL`, `UNLINK`, `EXPIRE`, and any `__call` passthrough.

### Configuration

```yaml
instrumentation:
    predis:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `db.system.name` | `predis` |
| `db.operation.name` | command name (e.g. `GET`, `SET`) |
| `db.redis.key` | cache key |
| `error.type` | exception class on error |

### Interfaces

#### `RedisSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.redis.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisSpanNameHandlerInterface;

class MyPredisSpanNameHandler implements RedisSpanNameHandlerInterface
{
    public function process(string $spanName, string $command, string $key): string
    {
        return sprintf('predis.%s %s', strtolower($command), $key);
    }
}
```

#### `RedisTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.redis.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisTraceIgnoreInterface;

class MyPredisTraceIgnore implements RedisTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, string $command, string $key): bool
    {
        return $command === 'PING';
    }
}
```

#### `RedisAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.redis.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisAttributeProviderInterface;

class MyPredisAttributeProvider implements RedisAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['command'], $context['key']
        return ['app.cache.prefix' => 'session'];
    }
}
```

#### `RedisMetricsInterface`

```php
interface RedisMetricsInterface
{
    public function recordCommand(string $command, float $durationMs): void;
    public function recordError(string $command, Throwable $exception, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultRedisMetrics` | Records `redis.client.requests_total`, `redis.client.duration_ms`, `redis.client.memory_usage`, `redis.client.errors_total`; `db.system` attribute is set to `predis` |

---

## Instrumentation: cache

### What it does

Decorates `cache.app` (`AdapterInterface`). Creates one `INTERNAL` span per `getItem()` call. Metering is enabled by default.

### Configuration

```yaml
instrumentation:
    cache:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: true   # enabled by default
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `cache.system` | `cache` |
| `cache.key` | cache item key |
| `cache.hit` | `true` / `false` |
| `error.type` | exception class on error |

### Interfaces

#### `CacheSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.cache.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheSpanNameHandlerInterface;

class MyCacheSpanNameHandler implements CacheSpanNameHandlerInterface
{
    public function process(string $spanName, string $key): string
    {
        return $spanName;
    }
}
```

#### `CacheTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.cache.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheTraceIgnoreInterface;

class MyCacheTraceIgnore implements CacheTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, string $key): bool
    {
        return str_starts_with($key, 'sf_meta_');
    }
}
```

#### `CacheAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.cache.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\CacheAttributeProviderInterface;

class MyCacheAttributeProvider implements CacheAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['key']
        return ['app.cache.pool' => 'app'];
    }
}
```

#### `CacheMetricsInterface`

```php
interface CacheMetricsInterface
{
    public function recordGet(string $key, bool $hit, float $durationMs): void;
    public function recordError(string $key, Throwable $exception): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultCacheMetrics` | Records `cache.requests_total`, `cache.duration_ms`, `cache.hits_total`, `cache.misses_total`, `cache.memory_usage`, `cache.errors_total` |

---

## Instrumentation: console

### What it does

Listens to `ConsoleEvents::COMMAND`, `ConsoleEvents::ERROR`, `ConsoleEvents::TERMINATE`. Creates one `SERVER` span per console command execution.

### Configuration

```yaml
instrumentation:
    console:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `console.system` | `console` |
| `console.command` | command name |
| `console.command.name` | command name |
| `console.command.class` | command class |
| `console.command.exit_code` | exit code on terminate |
| `error.type` | exception class on error |

### Interfaces

#### `ConsoleSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.console.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleSpanNameHandlerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

class MyConsoleSpanNameHandler implements ConsoleSpanNameHandlerInterface
{
    public function process(string $spanName, ConsoleCommandEvent $event): string
    {
        return $spanName;
    }
}
```

#### `ConsoleTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.console.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleTraceIgnoreInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

class MyConsoleTraceIgnore implements ConsoleTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, ConsoleCommandEvent $event): bool
    {
        return $event->getCommand()?->getName() === 'cache:warmup';
    }
}
```

#### `ConsoleAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.console.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleAttributeProviderInterface;

class MyConsoleAttributeProvider implements ConsoleAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['event'], $context['command']
        return ['app.worker' => 'node-1'];
    }
}
```

#### `ConsoleMetricsInterface`

```php
interface ConsoleMetricsInterface
{
    public function recordError(ConsoleErrorEvent $event): void;
    public function recordCommand(ConsoleTerminateEvent $event, string $commandName, float $durationMs): void;
    public function recordExitError(ConsoleTerminateEvent $event, string $commandName): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `MessengerConsumeTraceIgnore` | Ignores `messenger:consume` and `messenger:consume-messages` commands |
| `DefaultConsoleMetrics` | Records `console.command.requests_total`, `console.command.duration_ms`, `console.command.memory_usage`, `console.command.errors_total` |

---

## Instrumentation: events

### What it does

Decorates `event_dispatcher`. Creates one `INTERNAL` span per dispatched event — only when a parent span already exists.

### Configuration

```yaml
instrumentation:
    events:
        enabled: true
        default_trace_ignore_enabled: true    # registers DefaultEventTraceIgnore
        default_span_name_handler_enabled: true # registers DefaultEventSpanNameHandler
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `event.class` | fully qualified event class name |
| `error.type` | exception class on error |

### Interfaces

#### `EventSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.event.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventSpanNameHandlerInterface;

class MyEventSpanNameHandler implements EventSpanNameHandlerInterface
{
    public function process(string $spanName, object $event, ?string $eventName = null): string
    {
        return $spanName;
    }
}
```

#### `EventTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.event.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventTraceIgnoreInterface;

class MyEventTraceIgnore implements EventTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, object $event, ?string $eventName = null): bool
    {
        return $event instanceof SomeNoisyEvent;
    }
}
```

#### `EventAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.event.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\Interfaces\EventAttributeProviderInterface;

class MyEventAttributeProvider implements EventAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['event'], $context['eventName']
        return [];
    }
}
```

#### `EventDispatcherMetricsInterface`

```php
interface EventDispatcherMetricsInterface
{
    public function recordDispatch(object $event, ?string $eventName, float $durationMs): void;
    public function recordError(object $event, ?string $eventName, Throwable $throwable, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultEventSpanNameHandler` | Uses short class name: `event.dispatch {ShortClassName}` |
| `DefaultEventTraceIgnore` | Ignores all events whose class file is inside the `vendor/` directory |
| `DefaultEventDispatcherMetrics` | Records `event.dispatch.requests_total`, `event.dispatch.duration_ms`, `event.dispatch.memory_usage`, `event.dispatch.errors_total` |

---

## Instrumentation: messenger

### What it does

Registers a Messenger middleware (`messenger_tracing`). Creates one span per message:

- `PRODUCER` span when the message is dispatched (no `ReceivedStamp`)
- `CONSUMER` span when the message is consumed (has `ReceivedStamp`)

Automatically detects RabbitMQ/AMQP transport from stamps.

When `long_running_command_enabled: true`, `MessengerFlushSubscriber` forces `forceFlush()` on all providers after each message is processed in `messenger:consume`.

### Configuration

```yaml
instrumentation:
    messenger:
        enabled: true
        long_running_command_enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `messaging.message.type` | message class |
| `messaging.system` | `rabbitmq` or `symfony.messenger` |
| `messaging.operation.name` | `publish` or `process` |
| `messaging.destination.name` | transport name |
| `error.type` | exception class on error |

### Interfaces

#### `MessengerSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.messenger.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerSpanNameHandlerInterface;
use Symfony\Component\Messenger\Envelope;

class MyMessengerSpanNameHandler implements MessengerSpanNameHandlerInterface
{
    public function process(string $spanName, Envelope $envelope): string
    {
        return $spanName;
    }
}
```

#### `MessengerTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.messenger.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerTraceIgnoreInterface;
use Symfony\Component\Messenger\Envelope;

class MyMessengerTraceIgnore implements MessengerTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, Envelope $envelope): bool
    {
        return $envelope->getMessage() instanceof SomeNoisyMessage;
    }
}
```

#### `MessengerAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.messenger.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerAttributeProviderInterface;

class MyMessengerAttributeProvider implements MessengerAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['envelope'], $context['message']
        return [];
    }
}
```

#### `MessengerMetricsInterface`

```php
interface MessengerMetricsInterface
{
    public function recordMessage(object $message, string $operation, array $messagingAttributes, float $durationMs): void;
    public function recordError(object $message, string $operation, array $messagingAttributes, Throwable $exception, float $durationMs): void;
}
```

#### `LongRunningCommandInterface`

Identifies whether a console command is long-running (e.g. `messenger:consume`). Used to trigger `forceFlush()` after each message.

Service tag: `danilovl.open_telemetry.messenger.long_running_command`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\LongRunningCommandInterface;

class MyLongRunningCommand implements LongRunningCommandInterface
{
    public function isLongRunning(string $commandName): bool
    {
        return in_array($commandName, ['messenger:consume', 'my:worker'], true);
    }
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultLongRunningCommand` | Recognizes `messenger:consume` and `messenger:consume-messages` |
| `DefaultMessengerMetrics` | Records `messenger.message.requests_total`, `messenger.message.duration_ms`, `messenger.message.memory_usage`, `messenger.message.errors_total` |

---

## Instrumentation: mailer

### What it does

Listens to `MessageEvent`, `SentMessageEvent`, `FailedMessageEvent`. Creates one `PRODUCER` span per email send attempt.

### Configuration

```yaml
instrumentation:
    mailer:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `mailer.system` | `mailer` |
| `email.class` | message class |
| `email.transport` | transport name |
| `email.message_id` | message ID (on success) |
| `error.type` | exception class on error |

### Interfaces

#### `MailerSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.mailer.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerSpanNameHandlerInterface;
use Symfony\Component\Mailer\Event\MessageEvent;

class MyMailerSpanNameHandler implements MailerSpanNameHandlerInterface
{
    public function process(string $spanName, MessageEvent $event): string
    {
        return $spanName;
    }
}
```

#### `MailerTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.mailer.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces\MailerTraceIgnoreInterface;
use Symfony\Component\Mailer\Event\MessageEvent;

class MyMailerTraceIgnore implements MailerTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, MessageEvent $event): bool
    {
        return false;
    }
}
```

#### `MailerMetricsInterface`

```php
interface MailerMetricsInterface
{
    public function recordSent(object $message, string $transport, float $durationMs): void;
    public function recordFailed(object $message, string $transport, Throwable $error, float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultMailerMetrics` | Records `mailer.message.requests_total`, `mailer.message.duration_ms`, `mailer.message.memory_usage`, `mailer.message.errors_total` |

---

## Instrumentation: twig

### What it does

Extends Twig using `ProfilerNodeVisitor`. Creates one `INTERNAL` span per template render, block, and macro call.

Requires `twig/twig` package.

### Configuration

```yaml
instrumentation:
    twig:
        enabled: true
        tracing:
            enabled: true
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `twig.system` | `twig` |

Span name pattern:

- Root profile: `twig {name}`
- Template: `twig {template}`
- Block/macro: `twig {template}::{type}({name})`

### Interfaces

#### `TwigSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.twig.span_name_handler`

Implement `processProfile(string $spanName, Profile $profile): string` in your class (duck-typed, called via `method_exists`).

#### `TwigTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.twig.trace_ignore`

Implement `shouldIgnoreProfile(string $spanName, Profile $profile): bool` in your class (duck-typed, called via `method_exists`).

#### `TwigAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.twig.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\Interfaces\TwigAttributeProviderInterface;

class MyTwigAttributeProvider implements TwigAttributeProviderInterface
{
    public function provide(array $context): array
    {
        // $context['profile'] is Twig\Profiler\Profile
        return [];
    }
}
```

---

## Instrumentation: async

### What it does

Listens to `AsyncPreCallEvent` and `AsyncPostCallEvent` from [`danilovl/async-bundle`](https://github.com/danilovl/async-bundle). Creates one `SERVER` span per async call.

Requires `danilovl/async-bundle` package.

### Configuration

```yaml
instrumentation:
    async:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `async.system` | `async` |

### Interfaces

#### `AsyncMetricsInterface`

```php
interface AsyncMetricsInterface
{
    public function recordCall(float $durationMs): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultAsyncMetrics` | Records `async.requests_total`, `async.duration_ms`, `async.memory_usage` |

---

## Instrumentation: traceable

### What it does

The `traceable` instrumentation has two modes:

**1. `TraceableSubscriber` — PHP attribute on controllers and console commands**

Reads the `#[Traceable]` PHP attribute from controller classes/methods or console command classes. Creates one `INTERNAL` span when the annotated controller or command runs.

**2. `TraceableHookSubscriber` — OpenTelemetry hook on any service method**

At container compile time (`TraceableHookCompilerPass`), scans all registered services for the `#[Traceable]` attribute on class or method level. Registers an OpenTelemetry `hook()` for each matching public method. This uses the `ext-opentelemetry` hook API and does not require any event listener.

### The `#[Traceable]` attribute

#### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `name` | `string\|null` | `null` | Custom span name. Defaults to `class::method`, `traceable.controller`, or `traceable.console` depending on context. |
| `attributes` | `array<string, mixed>` | `[]` | Custom key-value pairs added as span attributes. |
| `handler` | `TraceableHandler` | `TraceableHandler::HOOK` | Selects which subscriber processes this attribute. See values below. |

#### `TraceableHandler` values

| Value | Handled by | Span lifecycle | Use when |
|-------|-----------|----------------|----------|
| `TraceableHandler::HOOK` (**default**) | `TraceableHookSubscriber` | method entry → method exit | Regular services |
| `TraceableHandler::CONTROLLER` | `TraceableSubscriber::onController` | `KernelEvents::CONTROLLER` → `KernelEvents::TERMINATE` | Controllers (all routes configs) |
| `TraceableHandler::COMMAND` | `TraceableSubscriber::onConsoleCommand` | `ConsoleEvents::COMMAND` → `ConsoleEvents::TERMINATE` + exit code | Console commands |

> **Rule:** `#[Traceable]` without `handler` defaults to `TraceableHandler::HOOK` — processed by `TraceableHookSubscriber`.
> For controllers use `handler: TraceableHandler::CONTROLLER`, for console commands use `handler: TraceableHandler::COMMAND`.

#### Examples

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{Traceable, TraceableHandler};

// Controller — always set handler: CONTROLLER
#[Traceable(name: 'orders.controller', handler: TraceableHandler::CONTROLLER, attributes: ['app.module' => 'orders'])]
class OrderController
{
    #[Route('/orders')]
    public function index(): Response { ... }
}

// Controller with routes in YAML/XML
#[Traceable(name: 'conversation.controller', handler: TraceableHandler::CONTROLLER)]
class ConversationController
{
    public function list(Request $request): Response { ... }
}

// Specific controller action only
class ProductController
{
    #[Traceable(name: 'product.show', handler: TraceableHandler::CONTROLLER)]
    public function show(int $id): Response { ... }
}

// Regular service — default handler is HOOK, no need to specify
class PaymentService
{
    #[Traceable(name: 'payment.process', attributes: ['app.domain' => 'billing'])]
    public function process(Payment $payment): void { ... }
}

// Whole service class traced via hook
#[Traceable(name: 'order.service')]
class OrderService
{
    public function create(array $data): Order { ... }
    public function cancel(int $id): void { ... }
}

// Console command — always set handler: COMMAND
#[Traceable(name: 'import.command', handler: TraceableHandler::COMMAND)]
class ImportDataCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int { ... }
}
```

### Configuration

```yaml
instrumentation:
    traceable:
        enabled: true
        tracing:
            enabled: true
        metering:
            enabled: false
```

### Span attributes

| Attribute | Source |
|-----------|--------|
| `traceable.type` | `controller`, `console_command`, or `service_method` |
| `traceable.class` | class name (for console_command and service_method) |
| `traceable.method` | method name (for service_method) |
| `traceable.exit_code` | exit code (for console_command) |
| custom attributes | from `#[Traceable(attributes: [...])]` |
| `error.type` | exception class on error |

### Interfaces

#### `TraceableSpanNameHandlerInterface`

Service tag: `danilovl.open_telemetry.traceable.span_name_handler`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableSpanNameHandlerInterface;

class MyTraceableSpanNameHandler implements TraceableSpanNameHandlerInterface
{
    public function process(string $spanName, array $context): string
    {
        // $context keys: operation, event/command/class/method, traceable, arguments
        return $spanName;
    }
}
```

#### `TraceableTraceIgnoreInterface`

Service tag: `danilovl.open_telemetry.traceable.trace_ignore`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableTraceIgnoreInterface;

class MyTraceableTraceIgnore implements TraceableTraceIgnoreInterface
{
    public function shouldIgnore(string $spanName, array $context): bool
    {
        return false;
    }
}
```

#### `TraceableAttributeProviderInterface`

Service tag: `danilovl.open_telemetry.traceable.attribute_provider`

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\Interfaces\TraceableAttributeProviderInterface;

class MyTraceableAttributeProvider implements TraceableAttributeProviderInterface
{
    public function provide(array $context): array
    {
        return ['app.user' => 'system'];
    }
}
```

#### `TraceableMetricsInterface`

Only used by `TraceableHookSubscriber` (service method hooks).

```php
interface TraceableMetricsInterface
{
    public function recordServiceMethod(string $className, string $methodName, float $durationMs): void;
    public function recordServiceMethodError(string $className, string $methodName, Throwable $throwable): void;
}
```

### Default implementations

| Class | Description |
|-------|-------------|
| `DefaultTraceableMetrics` | Records `traceable.service_method.requests_total`, `traceable.service_method.duration_ms`, `traceable.service_method.memory_usage`, `traceable.service_method.errors_total` |

---

## Metrics

All instrumentation sections support a `metering` block:

```yaml
instrumentation:
    http_server:
        metering:
            enabled: true
```

When metering is enabled, each instrumentation's default metrics class is activated automatically. All metrics use the shared `MetricsRecorder` service which wraps the OpenTelemetry `MeterProvider`.

### MetricsRecorderInterface

The `MetricsRecorderInterface` provides the following methods:

```php
interface MetricsRecorderInterface
{
    public function addCounter(string $name, float|int $amount = 1, array $attributes = [], ?string $unit = null, ?string $description = null): void;
    public function addUpDownCounter(string $name, float|int $amount = 1, array $attributes = [], ?string $unit = null, ?string $description = null): void;
    public function recordHistogram(string $name, float|int $amount, array $attributes = [], ?string $unit = null, ?string $description = null): void;
    public function recordGauge(string $name, float|int $amount, array $attributes = [], ?string $unit = null, ?string $description = null): void;
    public function createObservableCounter(string $name, ...): ObservableCounterInterface;
    public function createObservableGauge(string $name, ...): ObservableGaugeInterface;
    public function createObservableUpDownCounter(string $name, ...): ObservableUpDownCounterInterface;
    public function batchObserve(callable $callback, AsynchronousInstrument $instrument, ...): ObservableCallbackInterface;
}
```

You can inject `MetricsRecorderInterface` into your own services to record custom metrics.

### Metrics reference by instrumentation

| Instrumentation | Metric | Type | Attributes |
|-----------------|--------|------|------------|
| `http_server` | `http.server.requests_total` | counter | `http.method`, `http.route`, `http.status_code` |
| `http_server` | `http.server.duration_ms` | histogram | same |
| `http_server` | `http.server.memory_usage` | gauge | same |
| `http_server` | `http.server.errors_total` | counter | `http.method`, `http.route`, `error.type` |
| `http_client` | `http.client.requests_total` | counter | `http.method`, `http.host`, `http.status_code` |
| `http_client` | `http.client.duration_ms` | histogram | same |
| `http_client` | `http.client.memory_usage` | gauge | same |
| `http_client` | `http.client.errors_total` | counter | `http.method`, `http.host`, `error.type` |
| `doctrine` | `db.client.requests_total` | counter | `db.system`, `db.operation` |
| `doctrine` | `db.client.duration_ms` | histogram | same |
| `doctrine` | `db.client.memory_usage` | gauge | same |
| `doctrine` | `db.client.errors_total` | counter | `db.system`, `db.operation`, `error.type` |
| `redis` | `redis.client.requests_total` | counter | `db.system` (`redis`), `db.redis.command` |
| `redis` | `redis.client.duration_ms` | histogram | same |
| `redis` | `redis.client.memory_usage` | gauge | same |
| `redis` | `redis.client.errors_total` | counter | `db.system` (`redis`), `db.redis.command`, `error.type` |
| `predis` | `redis.client.requests_total` | counter | `db.system` (`predis`), `db.redis.command` |
| `predis` | `redis.client.duration_ms` | histogram | same |
| `predis` | `redis.client.memory_usage` | gauge | same |
| `predis` | `redis.client.errors_total` | counter | `db.system` (`predis`), `db.redis.command`, `error.type` |
| `cache` | `cache.requests_total` | counter | `cache.operation`, `cache.key`, `cache.hit` |
| `cache` | `cache.duration_ms` | histogram | same |
| `cache` | `cache.hits_total` | counter | same |
| `cache` | `cache.misses_total` | counter | same |
| `cache` | `cache.memory_usage` | gauge | same |
| `cache` | `cache.errors_total` | counter | `cache.operation`, `cache.key`, `error.type` |
| `console` | `console.command.requests_total` | counter | `console.command.name`, `console.command.exit_code` |
| `console` | `console.command.duration_ms` | histogram | same |
| `console` | `console.command.memory_usage` | gauge | same |
| `console` | `console.command.errors_total` | counter | `console.command.name`, `error.type` |
| `events` | `event.dispatch.requests_total` | counter | `event.class`, `event.name` |
| `events` | `event.dispatch.duration_ms` | histogram | same |
| `events` | `event.dispatch.memory_usage` | gauge | same |
| `events` | `event.dispatch.errors_total` | counter | `event.class`, `event.name`, `error.type` |
| `messenger` | `messenger.message.requests_total` | counter | `messaging.message.type`, `messaging.operation`, `messaging.system`, `messaging.destination.name` |
| `messenger` | `messenger.message.duration_ms` | histogram | same |
| `messenger` | `messenger.message.memory_usage` | gauge | same |
| `messenger` | `messenger.message.errors_total` | counter | same + `error.type` |
| `mailer` | `mailer.message.requests_total` | counter | `email.class`, `email.transport` |
| `mailer` | `mailer.message.duration_ms` | histogram | same |
| `mailer` | `mailer.message.memory_usage` | gauge | same |
| `mailer` | `mailer.message.errors_total` | counter | same + `error.type` |
| `async` | `async.requests_total` | counter | `async.operation` |
| `async` | `async.duration_ms` | histogram | same |
| `async` | `async.memory_usage` | gauge | same |
| `traceable` | `traceable.service_method.requests_total` | counter | `traceable.class`, `traceable.method` |
| `traceable` | `traceable.service_method.duration_ms` | histogram | same |
| `traceable` | `traceable.service_method.memory_usage` | gauge | same |
| `traceable` | `traceable.service_method.errors_total` | counter | same + `error.type` |

### Replacing a metrics implementation

Each instrumentation binds its metrics interface to a default implementation. You can replace it by registering your own class implementing the interface. The container alias will be updated automatically.

Example for `http_server`:

```php
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces\HttpServerMetricsInterface;
use Symfony\Component\HttpFoundation\Request;

class MyHttpServerMetrics implements HttpServerMetricsInterface
{
    public function recordRequest(Request $request, int $statusCode, float $durationMs): void
    {
        // custom metrics logic
    }

    public function recordError(Request $request, Throwable $exception): void
    {
        // custom error metrics logic
    }
}
```

Register it as a service in your `services.yaml`:

```yaml
services:
    App\Metrics\MyHttpServerMetrics:
        autowire: true
        autoconfigure: true
```

The bundle will detect it and use it instead of `DefaultHttpServerMetrics`.
