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
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DependencyInjection\Attribute\{
    AutowireIterator
};
use Throwable;

final class TracingCachePool implements AdapterInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.cache';

    /**
     * @param iterable<CacheAttributeProviderInterface> $cacheAttributeProviders
     * @param iterable<CacheSpanNameHandlerInterface> $cacheSpanNameHandlers
     * @param iterable<CacheTraceIgnoreInterface> $cacheTraceIgnores
     */
    public function __construct(
        private AdapterInterface $inner,
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::CACHE_ATTRIBUTE_PROVIDER)]
        private readonly iterable $cacheAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::CACHE_SPAN_NAME_HANDLER)]
        private readonly iterable $cacheSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::CACHE_TRACE_IGNORE)]
        private readonly iterable $cacheTraceIgnores = [],
        private readonly ?CacheMetricsInterface $cacheMetrics = null,
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

    public function getItem(mixed $key): CacheItem
    {
        $key = (string) $key;
        $spanName = 'cache get';

        foreach ($this->cacheSpanNameHandlers as $cacheSpanNameHandler) {
            $spanName = $cacheSpanNameHandler->process($spanName, $key);
        }

        foreach ($this->cacheTraceIgnores as $cacheTraceIgnore) {
            if ($cacheTraceIgnore->shouldIgnore($spanName, $key)) {
                return $this->inner->getItem($key);
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = $spanName === '' ? 'cache get' : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('transaction.type', 'cache')
            ->setAttribute('cache.system', 'cache')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->cacheAttributeProviders,
            context: ['key' => $key]
        );

        $scope = Context::storage()->attach(
            $span->storeInContext(Context::getCurrent())
        );

        try {
            $startTime = hrtime(true);
            $item = $this->inner->getItem($key);
            $span->setAttribute('cache.hit', $item->isHit());

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->cacheMetrics?->recordGet($key, $item->isHit(), $durationMs);

            return $item;
        } catch (Throwable $e) {
            $this->cacheMetrics?->recordError($key, $e);

            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param array<int, string> $keys
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->inner->clear($prefix);
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    /**
     * @param array<int, string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
