<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Redis;

use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    AbstractRedisHookInstrumentation,
    PhpRedisHookInstrumentation,
    PredisHookInstrumentation
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\{
    RedisAttributeProviderInterface,
    RedisSpanNameHandlerInterface,
    RedisTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\{
    SpyHookFunction,
    SpyRedisMetrics
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use PHPUnit\Framework\TestCase;
use Predis\Command\RawCommand;
use RuntimeException;

/** Hook callbacks are called directly, ext-opentelemetry is not needed. */
class RedisHookInstrumentationTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private SpyRedisMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new SpyRedisMetrics;
    }

    /**
     * @param class-string<AbstractRedisHookInstrumentation> $class
     * @param array<int, object> $nameHandlers
     * @param array<int, object> $ignores
     * @param array<int, object> $providers
     */
    private function create(
        string $class,
        array $nameHandlers = [],
        array $ignores = [],
        array $providers = [],
    ): AbstractRedisHookInstrumentation {
        /** @var iterable<RedisSpanNameHandlerInterface> $nameHandlers */
        /** @var iterable<RedisTraceIgnoreInterface> $ignores */
        /** @var iterable<RedisAttributeProviderInterface> $providers */
        return new $class(
            instrumentation: ScopedInstrumentation::create(AbstractRedisHookInstrumentation::INSTRUMENTATION_NAME),
            redisAttributeProviders: $providers,
            redisSpanNameHandlers: $nameHandlers,
            redisTraceIgnores: $ignores,
            redisMetrics: $this->metrics,
        );
    }

    public function testPhpRedisCommandSpan(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);

        $instrumentation->onPre(null, ['session:abc', 'value', 60], 'Redis', 'setex');
        $instrumentation->onPost(null, [], true, null);

        $span = $this->telemetry->span('SETEX');
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame('redis', $attributes['db.system.name']);
        $this->assertSame('SETEX', $attributes['db.operation.name']);
        $this->assertSame('session:abc', $attributes['db.redis.key']);
        $this->assertSame('phpredis', $attributes['db.redis.client']);
        $this->assertSame(['SETEX'], $this->metrics->commands);
        $this->assertSame(0, $instrumentation->getOpenSpanCount());
    }

    public function testMultiKeyCommandUsesFirstKey(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);

        $instrumentation->onPre(null, [['k1', 'k2']], 'Redis', 'mget');
        $instrumentation->onPost(null, [], [], null);
        $instrumentation->onPre(null, [['a' => 1, 'b' => 2]], 'Redis', 'mset');
        $instrumentation->onPost(null, [], true, null);

        $this->assertSame('k1', $this->telemetry->span('MGET')->getAttributes()->get('db.redis.key'));
        $this->assertSame('a', $this->telemetry->span('MSET')->getAttributes()->get('db.redis.key'));
    }

    public function testSecretArgumentsAreNeverRecordedAsKey(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);

        $instrumentation->onPre(null, ['super-secret-password'], 'Redis', 'auth');
        $instrumentation->onPost(null, [], true, null);
        $instrumentation->onPre(null, ['return redis.call("get", KEYS[1])', ['k'], 1], 'Redis', 'eval');
        $instrumentation->onPost(null, [], null, null);

        $this->assertNull($this->telemetry->span('AUTH')->getAttributes()->get('db.redis.key'));
        $this->assertNull($this->telemetry->span('EVAL')->getAttributes()->get('db.redis.key'));
    }

    public function testCommandExceptionIsRecorded(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);

        $instrumentation->onPre(null, ['k'], 'Redis', 'get');
        $instrumentation->onPost(null, [], null, new RuntimeException('READONLY'));

        $span = $this->telemetry->span('GET');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        $this->assertSame(['GET'], $this->metrics->errors);
    }

    public function testNestedCallsAreParentedAndBalanced(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);

        $instrumentation->onPre(null, [], 'Redis', 'multi');
        $instrumentation->onPre(null, ['k'], 'Redis', 'incr');
        $instrumentation->onPost(null, [], 1, null);
        $instrumentation->onPost(null, [], null, null);

        $multi = $this->telemetry->span('MULTI');
        $incr = $this->telemetry->span('INCR');
        $this->assertSame($multi->getSpanId(), $incr->getParentSpanId());
        $this->assertSame(0, $instrumentation->getOpenSpanCount());
    }

    public function testRedisSpanIsChildOfCurrentSpan(): void
    {
        $parent = $this->telemetry->tracerProvider->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $parent->activate();

        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);
        $instrumentation->onPre(null, ['k'], 'Redis', 'get');
        $instrumentation->onPost(null, [], 'v', null);

        $scope->detach();
        $parent->end();

        $this->assertSame($parent->getContext()->getSpanId(), $this->telemetry->span('GET')->getParentSpanId());
    }

    public function testUnbalancedPostIsIgnored(): void
    {
        $instrumentation = $this->create(PhpRedisHookInstrumentation::class);
        $instrumentation->onPost(null, [], null, null);

        $this->assertCount(0, $this->telemetry->spans());
    }

    public function testIgnoredCommandRecordsMetricsOnly(): void
    {
        $ignore = new class() implements RedisTraceIgnoreInterface {
            public function shouldIgnore(string $spanName, string $command, string $key): bool
            {
                return $command === 'PING';
            }
        };

        $instrumentation = $this->create(PhpRedisHookInstrumentation::class, ignores: [$ignore]);
        $instrumentation->onPre(null, [], 'Redis', 'ping');
        $instrumentation->onPost(null, [], true, null);

        $this->assertCount(0, $this->telemetry->spans());
        $this->assertSame(['PING'], $this->metrics->commands);
        $this->assertSame(0, $instrumentation->getOpenSpanCount());
    }

    public function testSpanNameHandlerAndAttributeProvider(): void
    {
        $handler = new class() implements RedisSpanNameHandlerInterface {
            public function process(string $spanName, string $command, string $key): string
            {
                return 'redis ' . $command . ' ' . explode(':', $key)[0];
            }
        };
        $provider = new class() implements RedisAttributeProviderInterface {
            public function provide(array $context): array
            {
                return ['app.cache_layer' => 'l2'];
            }
        };

        $instrumentation = $this->create(PhpRedisHookInstrumentation::class, nameHandlers: [$handler], providers: [$provider]);
        $instrumentation->onPre(null, ['user:1'], 'Redis', 'get');
        $instrumentation->onPost(null, [], null, null);

        $span = $this->telemetry->span('redis GET user');
        $this->assertSame('l2', $span->getAttributes()->get('app.cache_layer'));
    }

    public function testPredisCommandResolved(): void
    {
        $instrumentation = $this->create(PredisHookInstrumentation::class);
        $command = RawCommand::create('HGET', 'user:1', 'name');

        $instrumentation->onPre(null, [$command], 'Predis\\Client', 'executeCommand');
        $instrumentation->onPost(null, [$command], 'John', null);

        $span = $this->telemetry->span('HGET');
        $this->assertSame('user:1', $span->getAttributes()->get('db.redis.key'));
        $this->assertSame('predis', $span->getAttributes()->get('db.redis.client'));
    }

    public function testPredisAuthPasswordIsNotRecorded(): void
    {
        $instrumentation = $this->create(PredisHookInstrumentation::class);
        $command = RawCommand::create('AUTH', 'secret');

        $instrumentation->onPre(null, [$command], 'Predis\\Client', 'executeCommand');
        $instrumentation->onPost(null, [$command], true, null);

        $this->assertNull($this->telemetry->span('AUTH')->getAttributes()->get('db.redis.key'));
    }

    public function testPredisUnknownArgumentDoesNotBreak(): void
    {
        $instrumentation = $this->create(PredisHookInstrumentation::class);

        $instrumentation->onPre(null, ['not a command'], 'Predis\\Client', 'executeCommand');
        $instrumentation->onPost(null, [], null, null);

        $this->assertNotNull($this->telemetry->findSpan('UNKNOWN'));
    }

    public function testRegisterTwiceUsesLatestInstance(): void
    {
        $spy = SpyHookFunction::install();

        try {
            $old = $this->create(PredisHookInstrumentation::class);
            $this->assertTrue($old->register());

            // kernel reboot: new container, new instance
            $oldMetrics = $this->metrics;
            $this->metrics = new SpyRedisMetrics;
            $new = $this->create(PredisHookInstrumentation::class);
            $this->assertTrue($new->register());

            $this->assertSame(1, $spy->count('Predis\\ClientInterface::executeCommand'));

            $spy->call('Predis\\ClientInterface', 'executeCommand', [RawCommand::create('GET', 'k')]);

            $this->assertSame(['GET'], $this->telemetry->spanNames(), 'one span per command');
            $this->assertSame(['GET'], $this->metrics->commands, 'the new instance handles the hook');
            $this->assertSame([], $oldMetrics->commands);
        } finally {
            SpyHookFunction::uninstall();
        }
    }

    public function testRegisterWithoutExtensionDoesNothing(): void
    {
        if (AbstractRedisHookInstrumentation::isHookApiAvailable()) {
            $this->markTestSkipped('ext-opentelemetry is loaded');
        }

        $this->assertFalse($this->create(PhpRedisHookInstrumentation::class)->register());
    }

    public function testPhpRedisHookTargetsExist(): void
    {
        if (!class_exists('Redis')) {
            $this->markTestSkipped('ext-redis is not loaded');
        }

        $missing = array_filter(
            PhpRedisHookInstrumentation::COMMANDS,
            static fn (string $method): bool => !method_exists('Redis', $method)
        );

        foreach (['get', 'set', 'setex', 'del', 'hget', 'hset', 'lpush', 'xadd', 'eval', 'expire'] as $method) {
            $this->assertNotContains($method, $missing);
        }
    }
}
