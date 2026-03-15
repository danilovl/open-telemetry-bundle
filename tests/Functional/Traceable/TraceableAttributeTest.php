<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional\Traceable;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\{
    RecordingTracerProvider,
    RecordingTracerProviderFactory
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\TestKernel;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace\RecordingSpan;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};

class TraceableAttributeTest extends TestCase
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

    public function testTraceableSpanCreated(): void
    {
        $this->handle('/api/users');

        $this->assertNotNull($this->findSpan('api.users'), 'Traceable span "api.users" was not created');
    }

    public function testTraceableSpanIsEnded(): void
    {
        $this->handle('/api/users');

        $span = $this->findSpan('api.users');

        $this->assertNotNull($span);
        $this->assertTrue($span->isEnded());
    }

    public function testBothHttpAndTraceableSpansCreated(): void
    {
        $this->handle('/api/users');

        $this->assertNotNull($this->findSpan('GET /api/users'), 'HTTP span not found');
        $this->assertNotNull($this->findSpan('api.users'), 'Traceable span not found');
    }

    #[DataProvider('provideTraceableSpanAttributeCases')]
    public function testTraceableSpanAttribute(string $path, string $spanName, string $attrKey, mixed $expected): void
    {
        $this->handle($path);

        $span = $this->findSpan($spanName);
        $this->assertNotNull($span, "Traceable span '{$spanName}' was not created");
        $attrs = $span->getAttributes();

        $this->assertArrayHasKey($attrKey, $attrs);
        $this->assertSame($expected, $attrs[$attrKey]);
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public static function provideTraceableSpanAttributeCases(): Generator
    {
        yield 'api_users resource' => ['/api/users', 'api.users', 'resource', 'users'];
        yield 'api_attributes string' => ['/api/attributes', 'api.attributes', 'string', 'value'];
        yield 'api_attributes bool' => ['/api/attributes', 'api.attributes', 'bool', true];
        yield 'api_attributes int' => ['/api/attributes', 'api.attributes', 'int', 42];
        yield 'api_attributes float' => ['/api/attributes', 'api.attributes', 'float', 3.14];
        yield 'api_attributes array' => ['/api/attributes', 'api.attributes', 'array', '["a","b"]'];
    }
}
