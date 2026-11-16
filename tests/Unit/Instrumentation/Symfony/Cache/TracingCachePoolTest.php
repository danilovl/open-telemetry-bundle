<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Cache;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\{
    CacheSpanNameHandlerInterface,
    CacheTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\TracingCachePool;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyCacheMetrics;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\StatusCode;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\{
    AdapterInterface,
    ArrayAdapter,
    FilesystemAdapter
};
use Symfony\Component\Cache\Exception\BadMethodCallException;
use Symfony\Component\Cache\{
    PruneableInterface,
    ResettableInterface
};
use Symfony\Contracts\Cache\{
    CacheInterface,
    ItemInterface,
    NamespacedPoolInterface
};

class TracingCachePoolTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private SpyCacheMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new SpyCacheMetrics;
    }

    /**
     * @param array<int, object> $nameHandlers
     * @param array<int, object> $ignores
     */
    private function createPool(
        ?AdapterInterface $inner = null,
        array $nameHandlers = [],
        array $ignores = [],
    ): TracingCachePool {
        /** @var iterable<CacheSpanNameHandlerInterface> $nameHandlers */
        /** @var iterable<CacheTraceIgnoreInterface> $ignores */
        return new TracingCachePool(
            inner: $inner ?? new ArrayAdapter,
            instrumentation: ScopedInstrumentation::create(TracingCachePool::INSTRUMENTATION_NAME),
            cacheSpanNameHandlers: $nameHandlers,
            cacheTraceIgnores: $ignores,
            cacheMetrics: $this->metrics,
        );
    }

    public function testImplementsCachePoolInterfaces(): void
    {
        $pool = $this->createPool();

        $this->assertInstanceOf(CacheInterface::class, $pool);
        $this->assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $this->assertInstanceOf(AdapterInterface::class, $pool);
        $this->assertInstanceOf(NamespacedPoolInterface::class, $pool);
        $this->assertInstanceOf(PruneableInterface::class, $pool);
        $this->assertInstanceOf(ResettableInterface::class, $pool);
    }

    public function testContractsGetMissThenHit(): void
    {
        $pool = $this->createPool();
        $computed = 0;
        $callback = static function (ItemInterface $item) use (&$computed): string {
            $computed++;
            $item->expiresAfter(60);

            return 'value';
        };

        $first = $pool->get('user_1', $callback);
        $second = $pool->get('user_1', $callback);

        $this->assertSame('value', $first);
        $this->assertSame('value', $second);
        $this->assertSame(1, $computed);

        $spans = $this->telemetry->spans();
        $this->assertCount(2, $spans);
        $this->assertSame('cache get', $spans[0]->getName());
        $this->assertFalse($spans[0]->getAttributes()->get('cache.hit'));
        $this->assertTrue($spans[1]->getAttributes()->get('cache.hit'));
        $this->assertSame('user_1', $spans[0]->getAttributes()->get('cache.key'));
        $this->assertSame('cache.app', $spans[0]->getAttributes()->get('cache.pool'));

        $this->assertSame([['key' => 'user_1', 'hit' => false], ['key' => 'user_1', 'hit' => true]], $this->metrics->gets);
    }

    public function testNestedCacheCallIsChildSpan(): void
    {
        $pool = $this->createPool();

        $pool->get('outer', static fn (): string => $pool->get('inner', static fn (): string => 'x'));

        $outer = $this->telemetry->spans()[1];
        $inner = $this->telemetry->spans()[0];

        $this->assertSame('outer', $outer->getAttributes()->get('cache.key'));
        $this->assertSame($outer->getSpanId(), $inner->getParentSpanId());
    }

    public function testPsr6GetItemAndSave(): void
    {
        $pool = $this->createPool();

        $item = $pool->getItem('k');
        $this->assertFalse($item->isHit());

        $item->set(42);
        $this->assertTrue($pool->save($item));
        $this->assertTrue($pool->getItem('k')->isHit());
        $this->assertTrue($pool->hasItem('k'));
        $this->assertTrue($pool->deleteItem('k'));
        $this->assertFalse($pool->hasItem('k'));

        $this->assertSame(
            ['cache get', 'cache save', 'cache get', 'cache has', 'cache delete', 'cache has'],
            $this->telemetry->spanNames()
        );
    }

    public function testGetItemsDeleteAndClear(): void
    {
        $pool = $this->createPool();
        $pool->save($pool->getItem('a')->set(1));

        $items = iterator_to_array($pool->getItems(['a', 'b']));
        $this->assertTrue($items['a']->isHit());
        $this->assertFalse($items['b']->isHit());

        $span = $this->telemetry->span('cache get_multiple');
        $this->assertSame(2, $span->getAttributes()->get('cache.keys_count'));
        $this->assertSame(1, $span->getAttributes()->get('cache.hits'));

        $this->assertTrue($pool->delete('a'));
        $this->assertTrue($pool->deleteItems(['a', 'b']));
        $this->assertTrue($pool->clear());

        $this->assertNotNull($this->telemetry->findSpan('cache delete_multiple'));
        $this->assertNotNull($this->telemetry->findSpan('cache clear'));
    }

    public function testDeferredSaveAndCommit(): void
    {
        $pool = $this->createPool();
        $pool->saveDeferred($pool->getItem('d')->set('v'));
        $this->assertTrue($pool->commit());

        $this->assertTrue($pool->getItem('d')->isHit());
        $this->assertNotNull($this->telemetry->findSpan('cache commit'));
    }

    public function testWithSubNamespaceReturnsTracingPool(): void
    {
        $pool = $this->createPool();
        $sub = $pool->withSubNamespace('tenant1');

        $this->assertInstanceOf(TracingCachePool::class, $sub);
        $this->assertNotSame($pool, $sub);

        $sub->get('k', static fn (): string => 'sub');
        $this->assertFalse($pool->hasItem('k'), 'Sub-namespace must be isolated from the parent pool');
        $this->assertNotNull($this->telemetry->findSpan('cache get'));
    }

    public function testPruneAndResetAreDelegated(): void
    {
        $directory = sys_get_temp_dir() . '/otel_cache_prune_' . uniqid();
        $pool = $this->createPool(new FilesystemAdapter('', 0, $directory));

        $pool->get('k', static fn (): string => 'v');
        $this->assertTrue($pool->prune());

        $pool->reset();
        $this->assertSame('v', $pool->get('k', static fn (): string => 'other'));

        new FilesystemAdapter('', 0, $directory)->clear();
    }

    public function testPruneWithoutPruneableInner(): void
    {
        $inner = $this->createStub(AdapterInterface::class);

        $this->assertFalse($this->createPool($inner)->prune());
    }

    public function testContractsMethodsThrowForPsr6(): void
    {
        $pool = $this->createPool($this->createStub(AdapterInterface::class));

        $this->expectException(BadMethodCallException::class);
        $pool->get('k', static fn (): string => 'v');
    }

    private function failingCallback(): Closure
    {
        return static function (): string {
            throw new RuntimeException('backend down');
        };
    }

    public function testCallbackExceptionIsRecorded(): void
    {
        $pool = $this->createPool();

        try {
            $pool->get('failing', $this->failingCallback());
            $this->fail('Exception expected');
        } catch (RuntimeException $exception) {
            $this->assertSame('backend down', $exception->getMessage());
        }

        $span = $this->telemetry->span('cache get');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        $this->assertCount(1, $this->metrics->errors);
    }

    public function testIgnoredKeysAreNotTraced(): void
    {
        $ignore = new class() implements CacheTraceIgnoreInterface {
            public function shouldIgnore(string $spanName, string $key): bool
            {
                return str_starts_with($key, 'internal_');
            }
        };

        $pool = $this->createPool(ignores: [$ignore]);
        $pool->get('internal_x', static fn (): string => 'v');
        $pool->get('public_x', static fn (): string => 'v');

        $this->assertCount(1, $this->telemetry->spans());
        $this->assertSame('public_x', $this->telemetry->spans()[0]->getAttributes()->get('cache.key'));
    }

    public function testSpanNameHandler(): void
    {
        $handler = new class() implements CacheSpanNameHandlerInterface {
            public function process(string $spanName, string $key): string
            {
                return $spanName . ' ' . explode('_', $key)[0];
            }
        };

        $this->createPool(nameHandlers: [$handler])->get('user_42', static fn (): int => 1);

        $this->assertSame(['cache get user'], $this->telemetry->spanNames());
    }
}
