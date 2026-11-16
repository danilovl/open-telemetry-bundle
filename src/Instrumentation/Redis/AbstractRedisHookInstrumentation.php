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
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\{
    HookRegistry,
    ScopedInstrumentation
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\{
    Context,
    ContextStorageScopeInterface
};
use OpenTelemetry\SemConv\Attributes\{
    DbAttributes,
    ErrorAttributes
};
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/** Hooks do not replace the client object, so \Redis / Predis type hints keep working. */
abstract class AbstractRedisHookInstrumentation
{
    public const string INSTRUMENTATION_NAME = 'danilovl.redis';

    /**
     * First argument is not a key or is a secret (AUTH).
     *
     * @var list<string>
     */
    protected const array COMMANDS_WITHOUT_KEY = [
        'AUTH', 'HELLO', 'CLIENT', 'CONFIG', 'PING', 'ECHO', 'MULTI', 'EXEC', 'DISCARD',
        'FLUSHDB', 'FLUSHALL', 'SELECT', 'INFO', 'DBSIZE', 'EVAL', 'EVALSHA', 'EVAL_RO',
        'EVALSHA_RO', 'SCRIPT', 'SCAN', 'MIGRATE', 'SWAPDB', 'WAIT', 'ACL', 'CLUSTER',
    ];

    /**
     * Hooks are process-wide, the latest registered instance (current container) handles them.
     *
     * @var array<class-string, AbstractRedisHookInstrumentation>
     */
    private static array $active = [];

    /**
     * @var list<array{span: SpanInterface|null, scope: ContextStorageScopeInterface|null, command: string, startTime: int|float}>
     */
    private array $stack = [];

    /**
     * @param iterable<RedisAttributeProviderInterface> $redisAttributeProviders
     * @param iterable<RedisSpanNameHandlerInterface> $redisSpanNameHandlers
     * @param iterable<RedisTraceIgnoreInterface> $redisTraceIgnores
     */
    public function __construct(
        private readonly ScopedInstrumentation $instrumentation,
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

    public static function isHookApiAvailable(): bool
    {
        return HookRegistry::isAvailable();
    }

    public function register(): bool
    {
        if (!self::isHookApiAvailable()) {
            return false;
        }

        $owner = static::class;
        self::$active[$owner] = $this;

        foreach ($this->getHookTargets() as [$class, $method]) {
            HookRegistry::hook(
                owner: $owner,
                class: $class,
                method: $method,
                pre: static function (mixed ...$args) use ($owner): void {
                    self::$active[$owner]->onPre(...$args);
                },
                post: static function (mixed ...$args) use ($owner): void {
                    self::$active[$owner]->onPost(...$args);
                }
            );
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function onPre(mixed $object, array $params, ?string $class = null, ?string $function = null): void
    {
        [$command, $key] = $this->resolveCommand($params, (string) $function);

        $this->start($command, $key, $object);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function onPost(mixed $object, array $params, mixed $return = null, ?Throwable $exception = null): void
    {
        $this->finish($exception);
    }

    public function start(string $command, string $key, mixed $client = null): void
    {
        $startTime = hrtime(true);
        $spanName = 'redis';

        foreach ($this->redisSpanNameHandlers as $redisSpanNameHandler) {
            $spanName = $redisSpanNameHandler->process($spanName, $command, $key);
        }

        foreach ($this->redisTraceIgnores as $redisTraceIgnore) {
            if ($redisTraceIgnore->shouldIgnore($spanName, $command, $key)) {
                $this->stack[] = ['span' => null, 'scope' => null, 'command' => $command, 'startTime' => $startTime];

                return;
            }
        }

        /** @var non-empty-string $spanNameBuilder */
        $spanNameBuilder = $spanName === 'redis' || $spanName === '' ? $command : $spanName;

        $spanBuilder = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameBuilder)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, 'redis')
            ->setAttribute(DbAttributes::DB_OPERATION_NAME, $command)
            ->setAttribute('db.redis.client', $this->getClientName());

        if ($key !== '') {
            $spanBuilder->setAttribute('db.redis.key', $key);
        }

        $span = $spanBuilder->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->redisAttributeProviders,
            context: ['command' => $command, 'key' => $key, 'client' => $client]
        );

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));

        $this->stack[] = ['span' => $span, 'scope' => $scope, 'command' => $command, 'startTime' => $startTime];
    }

    public function finish(?Throwable $exception = null): void
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            return;
        }

        $durationMs = (hrtime(true) - $frame['startTime']) / 1_000_000;

        if ($exception !== null) {
            $this->redisMetrics?->recordError($frame['command'], $exception, $durationMs);
        } else {
            $this->redisMetrics?->recordCommand($frame['command'], $durationMs);
        }

        $span = $frame['span'];
        if (!$span instanceof SpanInterface) {
            return;
        }

        if ($exception !== null) {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $frame['scope']?->detach();
        $span->end();
    }

    public function getOpenSpanCount(): int
    {
        return count($this->stack);
    }

    protected static function extractKey(string $command, mixed $firstArgument): string
    {
        if (in_array($command, self::COMMANDS_WITHOUT_KEY, true)) {
            return '';
        }

        if (is_array($firstArgument)) {
            $firstArgument = array_is_list($firstArgument)
                ? ($firstArgument[0] ?? null)
                : array_key_first($firstArgument);
        }

        if (is_scalar($firstArgument) || $firstArgument instanceof Stringable) {
            return (string) $firstArgument;
        }

        return '';
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array{string, string} [COMMAND, key]
     */
    abstract protected function resolveCommand(array $params, string $function): array;

    /**
     * @return list<array{string, string}> list of [class, method]
     */
    abstract protected function getHookTargets(): array;

    abstract protected function getClientName(): string;
}
