<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Cache\Interfaces\{
    CacheAttributeProviderInterface,
    CacheMetricsInterface,
    CacheSpanNameHandlerInterface,
    CacheTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SpanAttributeEnricher;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\{
    CacheItem,
    PruneableInterface,
    ResettableInterface
};
use Symfony\Component\Cache\Exception\BadMethodCallException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Cache\{
    CacheInterface,
    ItemInterface,
    NamespacedPoolInterface
};
use Throwable;

/** Implements the same interfaces as Symfony pools, so every cache.app type hint keeps working. */
class TracingCachePool implements AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.cache';

    /**
     * @param iterable<CacheAttributeProviderInterface> $cacheAttributeProviders
     * @param iterable<CacheSpanNameHandlerInterface> $cacheSpanNameHandlers
     * @param iterable<CacheTraceIgnoreInterface> $cacheTraceIgnores
     */
    public function __construct(
        private AdapterInterface $inner,
        private readonly ScopedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::CACHE_ATTRIBUTE_PROVIDER)]
        private readonly iterable $cacheAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::CACHE_SPAN_NAME_HANDLER)]
        private readonly iterable $cacheSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::CACHE_TRACE_IGNORE)]
        private readonly iterable $cacheTraceIgnores = [],
        private readonly ?CacheMetricsInterface $cacheMetrics = null,
        private readonly string $poolName = 'cache.app',
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$cacheAttributeProviders',
            items: $this->cacheAttributeProviders,
            expectedType: CacheAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$cacheSpanNameHandlers',
            items: $this->cacheSpanNameHandlers,
            expectedType: CacheSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$cacheTraceIgnores',
            items: $this->cacheTraceIgnores,
            expectedType: CacheTraceIgnoreInterface::class
        );
    }

    /**
     * @param array<mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!$this->inner instanceof CacheInterface) {
            throw new BadMethodCallException(sprintf('Cannot call "%s::get()": this class doesn\'t implement "%s".', get_debug_type($this->inner), CacheInterface::class));
        }

        $inner = $this->inner;
        $isHit = true;
        $wrappedCallback = static function (ItemInterface $item, bool &$save) use ($callback, &$isHit): mixed {
            $isHit = false;

            return $callback($item, $save);
        };

        return $this->trace(
            operation: 'get',
            key: $key,
            call: static function () use ($inner, $key, $wrappedCallback, $beta, &$metadata): mixed {
                return $inner->get($key, $wrappedCallback, $beta, $metadata);
            },
            onSuccess: function (SpanInterface $span, float $durationMs) use ($key, &$isHit): void {
                $span->setAttribute('cache.hit', $isHit);
                $this->cacheMetrics?->recordGet($key, $isHit, $durationMs);
            }
        );
    }

    public function getItem(mixed $key): CacheItem
    {
        $key = (string) $key;
        $hit = false;

        /** @var CacheItem $item */
        $item = $this->trace(
            operation: 'get',
            key: $key,
            call: fn (): CacheItem => $this->inner->getItem($key),
            onSuccess: function (SpanInterface $span, float $durationMs, mixed $result) use ($key, &$hit): void {
                $hit = $result instanceof CacheItemInterface && $result->isHit();
                $span->setAttribute('cache.hit', $hit);
                $this->cacheMetrics?->recordGet($key, $hit, $durationMs);
            }
        );

        return $item;
    }

    /**
     * @param array<int, string> $keys
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        /** @var iterable<string, CacheItem> $items */
        $items = $this->trace(
            operation: 'get_multiple',
            key: implode(',', array_slice($keys, 0, 10)),
            call: fn (): array => iterator_to_array($this->inner->getItems($keys)),
            onSuccess: static function (SpanInterface $span, float $durationMs, mixed $result) use ($keys): void {
                $hits = 0;
                foreach (is_array($result) ? $result : [] as $item) {
                    if ($item instanceof CacheItemInterface && $item->isHit()) {
                        $hits++;
                    }
                }

                $span->setAttribute('cache.keys_count', count($keys));
                $span->setAttribute('cache.hits', $hits);
            }
        );

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return (bool) $this->trace('has', $key, fn (): bool => $this->inner->hasItem($key));
    }

    public function clear(string $prefix = ''): bool
    {
        return (bool) $this->trace('clear', $prefix, fn (): bool => $this->inner->clear($prefix));
    }

    public function delete(string $key): bool
    {
        if (!$this->inner instanceof CacheInterface) {
            throw new BadMethodCallException(sprintf('Cannot call "%s::delete()": this class doesn\'t implement "%s".', get_debug_type($this->inner), CacheInterface::class));
        }

        $inner = $this->inner;

        return (bool) $this->trace('delete', $key, static fn (): bool => $inner->delete($key));
    }

    public function deleteItem(string $key): bool
    {
        return (bool) $this->trace('delete', $key, fn (): bool => $this->inner->deleteItem($key));
    }

    /**
     * @param array<int, string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return (bool) $this->trace('delete_multiple', implode(',', array_slice($keys, 0, 10)), fn (): bool => $this->inner->deleteItems($keys));
    }

    public function save(CacheItemInterface $item): bool
    {
        return (bool) $this->trace('save', $item->getKey(), fn (): bool => $this->inner->save($item));
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return (bool) $this->trace('commit', '', fn (): bool => $this->inner->commit());
    }

    public function prune(): bool
    {
        if (!$this->inner instanceof PruneableInterface) {
            return false;
        }

        return $this->inner->prune();
    }

    public function reset(): void
    {
        if ($this->inner instanceof ResettableInterface) {
            $this->inner->reset();
        }
    }

    public function withSubNamespace(string $namespace): static
    {
        if (!$this->inner instanceof NamespacedPoolInterface) {
            throw new BadMethodCallException(sprintf('Cannot call "%s::withSubNamespace()": this class doesn\'t implement "%s".', get_debug_type($this->inner), NamespacedPoolInterface::class));
        }

        $clone = clone $this;
        $clone->inner = $this->inner->withSubNamespace($namespace);

        return $clone;
    }

    public function getInner(): AdapterInterface
    {
        return $this->inner;
    }

    /**
     * @param callable(): mixed $call
     * @param (callable(SpanInterface, float, mixed): void)|null $onSuccess
     */
    private function trace(string $operation, string $key, callable $call, ?callable $onSuccess = null): mixed
    {
        $spanName = 'cache ' . $operation;

        foreach ($this->cacheSpanNameHandlers as $cacheSpanNameHandler) {
            $spanName = $cacheSpanNameHandler->process($spanName, $key);
        }

        foreach ($this->cacheTraceIgnores as $cacheTraceIgnore) {
            if ($cacheTraceIgnore->shouldIgnore($spanName, $key)) {
                return $call();
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'cache ' . $operation : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.system', 'symfony')
            ->setAttribute('cache.pool', $this->poolName)
            ->setAttribute('cache.operation', $operation)
            ->setAttribute('cache.key', $key !== '' ? $key : null)
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->cacheAttributeProviders,
            context: ['key' => $key, 'operation' => $operation, 'pool' => $this->poolName]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        $startTime = hrtime(true);

        try {
            $result = $call();

            if ($onSuccess !== null) {
                $onSuccess($span, (hrtime(true) - $startTime) / 1_000_000, $result);
            }

            return $result;
        } catch (Throwable $e) {
            $this->cacheMetrics?->recordError($key, $e);

            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
