<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional\HttpKernel;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\{
    RecordingTracerProvider,
    RecordingTracerProviderFactory};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\TestKernel;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace\{
    RecordingSpan
};
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};

class SpanRecordingTest extends TestCase
{
    protected static TestKernel $kernel;

    private RecordingTracerProvider $provider;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel;
        static::$kernel->boot();
    }

    protected function setUp(): void
    {
        /** @var RecordingTracerProviderFactory $factory */
        $factory = static::$kernel->getContainer()->get(DefaultTracerProviderFactory::class);
        $this->provider = $factory->getProvider();
        $this->provider->reset();
    }

    private function handle(string $path, string $method = 'GET'): Response
    {
        $request = Request::create($path, $method);
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);

        return $response;
    }

    private function findSpan(string $name): ?RecordingSpan
    {
        return array_find($this->provider->getSpans(), static fn (RecordingSpan $span): bool => $span->getName() === $name);

    }

    #[DataProvider('provideHttpSpanNameCases')]
    public function testHttpSpanName(string $path, string $expectedName): void
    {
        $this->handle($path);

        $this->assertNotNull($this->findSpan($expectedName), "HTTP span '{$expectedName}' not found");
    }

    #[DataProvider('provideHttpSpanAttributeCases')]
    public function testHttpSpanAttribute(string $path, string $spanName, string $attrKey, mixed $expected): void
    {
        $this->handle($path);

        $span = $this->findSpan($spanName);

        $this->assertNotNull($span, "Span '{$spanName}' not found");

        $attrs = $span->getAttributes();

        $this->assertArrayHasKey($attrKey, $attrs);
        $this->assertSame($expected, $attrs[$attrKey]);
    }

    public function testHttpSpanEndedAfterTerminate(): void
    {
        $this->handle('/');

        $span = $this->findSpan('GET /');
        $this->assertNotNull($span);
        $this->assertTrue($span->isEnded());
    }

    #[DataProvider('provideIgnoredPathCreatesNoSpanCases')]
    public function testIgnoredPathCreatesNoSpan(string $path): void
    {
        $this->handle($path);

        $this->assertCount(0, $this->provider->getSpans());
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public static function provideHttpSpanNameCases(): Generator
    {
        yield 'home' => ['/', 'GET /'];
        yield 'api users' => ['/api/users', 'GET /api/users'];
    }

    public static function provideHttpSpanAttributeCases(): Generator
    {
        yield 'home method' => ['/', 'GET /', 'http.request.method', 'GET'];
        yield 'home path' => ['/', 'GET /', 'url.path', '/'];
        yield 'home status' => ['/', 'GET /', 'http.response.status_code', 200];
        yield 'home route' => ['/', 'GET /', 'http.route', 'home'];
        yield 'home server' => ['/', 'GET /', 'server.address', 'localhost'];
        yield 'api users route' => ['/api/users', 'GET /api/users', 'http.route', 'api_users'];
        yield 'api users status' => ['/api/users', 'GET /api/users', 'http.response.status_code', 200];
    }

    public static function provideIgnoredPathCreatesNoSpanCases(): Generator
    {
        yield 'wdt token' => ['/_wdt/token123'];
        yield 'wdt long token' => ['/_wdt/abc-def-123'];
        yield 'wdt nested' => ['/_wdt/a/b/c'];
    }
}
