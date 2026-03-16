<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SpanAttributeEnricher;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use OpenTelemetry\SemConv\Attributes\{
    DbAttributes,
    ErrorAttributes
};
use OpenTelemetry\Context\Context;
use Stringable;
use Throwable;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\{
    TracingPhpRedisInterface,
    RedisAttributeProviderInterface,
    RedisMetricsInterface,
    RedisSpanNameHandlerInterface,
    RedisTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class TracingPhpRedis implements TracingPhpRedisInterface
{
    /**
     * @param iterable<RedisAttributeProviderInterface> $redisAttributeProviders
     * @param iterable<RedisSpanNameHandlerInterface> $redisSpanNameHandlers
     * @param iterable<RedisTraceIgnoreInterface> $redisTraceIgnores
     */
    public function __construct(
        protected readonly Redis $redis,
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::REDIS_ATTRIBUTE_PROVIDER)]
        private readonly iterable $redisAttributeProviders = [],
        #[AutowireIterator(InstrumentationTags::REDIS_SPAN_NAME_HANDLER)]
        private readonly iterable $redisSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::REDIS_TRACE_IGNORE)]
        private readonly iterable $redisTraceIgnores = [],
        private readonly ?RedisMetricsInterface $redisMetrics = null,
    ) {
        AutowireIteratorTypeValidator::validate(
            argumentName: '$redisAttributeProviders',
            items: $this->redisAttributeProviders,
            expectedType: RedisAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$redisSpanNameHandlers',
            items: $this->redisSpanNameHandlers,
            expectedType: RedisSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$redisTraceIgnores',
            items: $this->redisTraceIgnores,
            expectedType: RedisTraceIgnoreInterface::class
        );
    }

    public function get(string $key): mixed
    {
        return $this->trace('GET', $key, fn () => $this->redis->get($key));
    }

    /**
     * @param int|array<string|int, mixed> $timeout
     */
    public function set(string $key, mixed $value, int|array $timeout = []): bool
    {
        return (bool) $this->trace('SET', $key, fn () => $this->redis->set($key, $value, $timeout));
    }

    public function setex(string $key, int $seconds, mixed $value): mixed
    {
        return $this->trace('SETEX', $key, fn () => $this->redis->setex($key, $seconds, $value));
    }

    /**
     * @param string|array<int, string> $key
     */
    public function del(string|array $key, string ...$otherKeys): int
    {
        $firstKey = is_array($key) ? (string) ($key[0] ?? '') : $key;

        return (int) $this->trace('DEL', $firstKey, fn () => $this->redis->del($key, ...$otherKeys));
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->trace('EXPIRE', $key, fn () => $this->redis->expire($key, $seconds));
    }

    /**
     * @param string $method
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $command = mb_strtoupper($method);
        $key = '';

        if (array_key_exists(0, $arguments) && (is_scalar($arguments[0]) || $arguments[0] instanceof Stringable)) {
            $key = (string) $arguments[0];
        }

        return $this->trace($command, $key, fn () => $this->redis->{$method}(...$arguments));
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function trace(string $command, string $key, callable $call): mixed
    {
        $spanName = 'redis';

        foreach ($this->redisSpanNameHandlers as $redisSpanNameHandler) {
            $spanName = $redisSpanNameHandler->process($spanName, $command, $key);
        }

        foreach ($this->redisTraceIgnores as $redisTraceIgnore) {
            if ($redisTraceIgnore->shouldIgnore($spanName, $command, $key)) {
                return $call();
            }
        }

        /** @var non-empty-string $spanNameBuilder */
        $spanNameBuilder = $spanName === 'redis' ? sprintf('redis.%s', mb_strtolower($command)) : $spanName;

        $span = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameBuilder)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, 'redis')
            ->setAttribute(DbAttributes::DB_OPERATION_NAME, mb_strtoupper($command))
            ->setAttribute('db.redis.key', $key)
            ->setAttribute('class', $this->redis::class)
            ->setAttribute('type', 'redis')
            ->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->redisAttributeProviders,
            context: ['command' => $command, 'key' => $key]
        );

        $scope = Context::storage()->attach(
            $span->storeInContext(Context::getCurrent())
        );

        $startTime = hrtime(true);

        try {
            $result = $call();

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->redisMetrics?->recordCommand($command, $durationMs);

            return $result;
        } catch (Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->redisMetrics?->recordError($command, $e, $durationMs);

            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);
            $span = $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
