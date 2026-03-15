<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis;

use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\{
    RedisAttributeProviderInterface,
    RedisMetricsInterface,
    RedisSpanNameHandlerInterface,
    RedisTraceIgnoreInterface
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
use OpenTelemetry\SemConv\Attributes\{
    DbAttributes,
    ErrorAttributes
};
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Redis;
use RuntimeException;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\{
    AsDecorator,
    AutowireDecorated,
    AutowireIterator
};
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

#[AsDecorator(decorates: 'redis_session', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class TracingRedis implements ClientInterface
{
    /**
     * @param iterable<RedisAttributeProviderInterface> $redisAttributeProviders
     * @param iterable<RedisSpanNameHandlerInterface> $redisSpanNameHandlers
     * @param iterable<RedisTraceIgnoreInterface> $redisTraceIgnores
     */
    public function __construct(
        #[AutowireDecorated]
        private Redis|ClientInterface $redis,
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

    public function set(string $key, mixed $value, int $expireResolution = 0): bool
    {
        if ($expireResolution > 0) {
            return (bool) $this->setEx($key, $expireResolution, $value);
        }

        return (bool) $this->trace('SET', $key, fn () => $this->redis->set($key, $value));
    }

    public function setEx(string $key, int $seconds, mixed $value): mixed
    {
        return $this->trace('SETEX', $key, function () use ($key, $seconds, $value) {
            if (method_exists($this->redis, 'setEx')) {
                return $this->redis->setEx($key, $seconds, $value);
            }

            if ($this->redis instanceof ClientInterface) {
                return $this->redis->set($key, $value, 'EX', $seconds);
            }

            return $this->redis->set($key, $value, ['EX' => $seconds]);
        });
    }

    /**
     * @param string|array<int, string> $keyOrKeys
     * @param string ...$keys
     */
    public function del(string|array $keyOrKeys, string ...$keys): mixed
    {
        $firstKey = is_array($keyOrKeys) ? (string) ($keyOrKeys[0] ?? '') : $keyOrKeys;

        return $this->trace('DEL', $firstKey, fn () => $this->redis->del($keyOrKeys, ...$keys));
    }

    /**
     * @param string|array<int, string> $key
     * @param string ...$keys
     */
    public function unlink(string|array $key, string ...$keys): mixed
    {
        $firstKey = is_array($key) ? (string) ($key[0] ?? '') : $key;

        return $this->trace('UNLINK', $firstKey, function () use ($key, $keys) {
            if (method_exists($this->redis, 'unlink')) {
                return $this->redis->unlink($key, ...$keys);
            }

            return $this->redis->del($key, ...$keys);
        });
    }

    public function expire(string $key, int $seconds): mixed
    {
        return $this->trace('EXPIRE', $key, fn () => $this->redis->expire($key, $seconds));
    }

    public function getCommandFactory()
    {
        return $this->getPredisClient()->getCommandFactory();
    }

    public function getOptions()
    {
        return $this->getPredisClient()->getOptions();
    }

    public function connect(): void
    {
        $this->getPredisClient()->connect();
    }

    public function disconnect(): void
    {
        $this->getPredisClient()->disconnect();
    }

    public function getConnection()
    {
        return $this->getPredisClient()->getConnection();
    }

    /**
     * @param string $method
     * @param array<int|string, mixed> $arguments
     * @return mixed
     */
    public function createCommand($method, $arguments = [])
    {
        return $this->getPredisClient()->createCommand($method, $arguments);
    }

    public function executeCommand(CommandInterface $command)
    {
        return $this->trace($command->getId(), '', fn () => $this->getPredisClient()->executeCommand($command));
    }

    /**
     * @param string $method
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        $command = mb_strtoupper((string) $method);
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
    private function trace(string $command, string $key, callable $call)
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
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function getPredisClient(): ClientInterface
    {
        if ($this->redis instanceof ClientInterface) {
            return $this->redis;
        }

        throw new RuntimeException('Predis client is not available for this tracing redis instance.');
    }
}
