<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Symfony\HttpKernel\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\TraceIgnore\DefaultHttpRequestTraceIgnore;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class DefaultHttpRequestTraceIgnoreTest extends TestCase
{
    private DefaultHttpRequestTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new DefaultHttpRequestTraceIgnore;
    }

    private function makeEvent(string $path, string $method = 'GET'): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create($path, $method);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeSubEvent(string $path): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create($path);

        return new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);
    }

    #[DataProvider('provideIgnoredPathsCases')]
    public function testIgnoredPaths(string $path): void
    {
        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $this->makeEvent($path)));
    }

    #[DataProvider('provideNotIgnoredPathsCases')]
    public function testNotIgnoredPaths(string $path): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $this->makeEvent($path)));
    }

    public function testSpanNameIsNotUsed(): void
    {
        $event = $this->makeEvent('/_wdt/abc');

        $this->assertTrue($this->traceIgnore->shouldIgnore('any.span.name', $event));
        $this->assertTrue($this->traceIgnore->shouldIgnore('', $event));
    }

    public function testSubrequestIsAlsoChecked(): void
    {
        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $this->makeSubEvent('/_wdt/sub')));
    }

    public function testSubrequestNotIgnoredForNormalPath(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $this->makeSubEvent('/api/users')));
    }

    #[DataProvider('provideIgnoredForAnyHttpMethodCases')]
    public function testIgnoredForAnyHttpMethod(string $method): void
    {
        $event = $this->makeEvent('/_wdt/token123', $method);
        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testIgnoredWithQueryString(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/_wdt/abc123', 'GET', ['debug' => '1', 'token' => 'xyz']);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testIgnoredWithPostBody(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/_wdt/token', 'POST', ['data' => 'value']);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testIgnoredWithCustomHeaders(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/_wdt/token');
        $request->headers->set('X-Custom-Header', 'value');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->assertTrue($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testNotIgnoredWhenPathIsExact_wdt(): void
    {
        $event = $this->makeEvent('/_wdt');
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $event));
    }

    public function testMultipleCallsSameEventReturnSameResult(): void
    {
        $event = $this->makeEvent('/_wdt/abc');
        $first = $this->traceIgnore->shouldIgnore('span', $event);
        $second = $this->traceIgnore->shouldIgnore('span', $event);
        $this->assertSame($first, $second);
        $this->assertTrue($first);
    }

    #[DataProvider('provideEdgeCasePathsNotIgnoredCases')]
    public function testEdgeCasePathsNotIgnored(string $path): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('span', $this->makeEvent($path)));
    }

    public static function provideIgnoredPathsCases(): Generator
    {
        yield '/_wdt/ prefix' => ['/_wdt/abc123'];
        yield '/_wdt/ with longer path' => ['/_wdt/toolbar/abc'];
        yield '/_wdt/ root' => ['/_wdt/'];
        yield '/_wdt/ deeply nested' => ['/_wdt/a/b/c/d/e'];
        yield '/_wdt/ with dash' => ['/_wdt/some-token-123'];
        yield '/_wdt/ with underscore' => ['/_wdt/some_section/detail'];
    }

    public static function provideNotIgnoredPathsCases(): Generator
    {
        yield '/api/users' => ['/api/users'];
        yield '/' => ['/'];
        yield '/_wdt' => ['/_wdt'];
        yield '/_profiler/foo' => ['/_profiler/foo'];
        yield '/app/dashboard' => ['/app/dashboard'];
        yield '/wdt/something' => ['/wdt/something'];
        yield '/api/_wdt/test' => ['/api/_wdt/test'];
    }

    public static function provideIgnoredForAnyHttpMethodCases(): Generator
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
        yield 'PATCH' => ['PATCH'];
    }

    public static function provideEdgeCasePathsNotIgnoredCases(): Generator
    {
        yield 'path starting with /_wdtx/' => ['/_wdtx/abc'];
        yield 'path /_wdt without slash' => ['/_wdtabc'];
        yield 'uppercase /_WDT/' => ['/_WDT/abc'];
    }
}
