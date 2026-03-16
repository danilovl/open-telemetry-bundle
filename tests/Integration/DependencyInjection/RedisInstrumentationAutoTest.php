<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\OpenTelemetryCompilerPass;
use Danilovl\OpenTelemetryBundle\Tests\Mock\DependencyInjection\TestableOpenTelemetryExtension;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Redis\MockPredisClient;
use Predis\ClientInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    TracingRedis,
    TracingPhpRedis
};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\{
    ContainerBuilder,
    Definition
};
use Redis;
use LogicException;

class RedisInstrumentationAutoTest extends TestCase
{
    private TestableOpenTelemetryExtension $extension;

    private OpenTelemetryCompilerPass $compilerPass;

    protected function setUp(): void
    {
        $this->extension = new TestableOpenTelemetryExtension;
        $this->compilerPass = new OpenTelemetryCompilerPass;
    }

    public function testRedisInstrumentationTypePredis(): void
    {
        $container = new ContainerBuilder;

        $container->setDefinition('my_redis', new Definition(MockPredisClient::class))
            ->setPublic(true);

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => false
                ],
                'predis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
        $this->compilerPass->process($container);

        $this->assertTrue($container->hasDefinition('my_redis.otel_tracing'));
        $definition = $container->getDefinition('my_redis.otel_tracing');

        $this->assertSame(TracingRedis::class, $definition->getClass());
        $this->assertNotNull($definition->getArgument('$instrumentation'));
        $this->assertNotNull($definition->getArgument('$redisMetrics'));

        $this->assertTrue($container->hasAlias('danilovl.open_telemetry.instrumentation.redis.default'));
        if (interface_exists(ClientInterface::class)) {
            $this->assertTrue($container->hasAlias(ClientInterface::class));
            $this->assertSame('my_redis.otel_tracing', (string) $container->getAlias(ClientInterface::class));
        }
    }

    public function testRedisInstrumentationTypeRedis(): void
    {
        $container = new ContainerBuilder;

        $container->setDefinition('my_php_redis', new Definition(Redis::class))
            ->setPublic(true);

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
        $this->compilerPass->process($container);

        $this->assertTrue($container->hasDefinition('my_php_redis.otel_tracing'));
        $definition = $container->getDefinition('my_php_redis.otel_tracing');

        $this->assertSame(TracingPhpRedis::class, $definition->getClass());
        $this->assertNotNull($definition->getArgument('$instrumentation'));
        $this->assertNotNull($definition->getArgument('$redisMetrics'));

        $this->assertTrue($container->hasAlias('danilovl.open_telemetry.instrumentation.redis.default'));
        if (class_exists(Redis::class)) {
            $this->assertTrue($container->hasAlias('Redis'));
            $this->assertSame('my_php_redis.otel_tracing', (string) $container->getAlias('Redis'));
        }
    }

    public function testRedisInstrumentationMultipleServices(): void
    {
        $container = new ContainerBuilder;

        $container->setDefinition('redis1', new Definition(MockPredisClient::class))->setPublic(true);
        $container->setDefinition('redis2', new Definition(MockPredisClient::class))->setPublic(true);

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => false
                ],
                'predis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
        $this->compilerPass->process($container);

        $this->assertTrue($container->hasDefinition('redis1.otel_tracing'));
        $this->assertTrue($container->hasDefinition('redis2.otel_tracing'));

        $hasAlias = $container->hasAlias('danilovl.open_telemetry.instrumentation.redis.default');

        $this->assertTrue($hasAlias);
    }

    public function testRedisInstrumentationStrictTypeSelection(): void
    {
        $container = new ContainerBuilder;

        $container->setDefinition('php_redis_service', new Definition(Redis::class))->setPublic(true);
        $container->setDefinition('predis_service', new Definition(MockPredisClient::class))->setPublic(true);

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => true
                ],
                'predis' => [
                    'enabled' => false
                ]
            ]
        ];

        $this->extension->load([$config], $container);
        $this->compilerPass->process($container);

        $hasDefinition = $container->hasDefinition('php_redis_service.otel_tracing');
        $this->assertTrue($hasDefinition);

        $this->assertSame(TracingPhpRedis::class, $container->getDefinition('php_redis_service.otel_tracing')->getClass());

        $this->assertFalse($container->hasDefinition('predis_service.otel_tracing'));
    }

    public function testRedisInstrumentationStrictTypeSelectionPredis(): void
    {
        $container = new ContainerBuilder;

        $container->setDefinition('php_redis_service', new Definition(Redis::class))->setPublic(true);
        $container->setDefinition('predis_service', new Definition(MockPredisClient::class))->setPublic(true);

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => false
                ],
                'predis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
        $this->compilerPass->process($container);

        $hasDefinition = $container->hasDefinition('predis_service.otel_tracing');
        $this->assertTrue($hasDefinition);

        $this->assertSame(TracingRedis::class, $container->getDefinition('predis_service.otel_tracing')->getClass());

        $hasDefinition = $container->hasDefinition('php_redis_service.otel_tracing');
        $this->assertFalse($hasDefinition);
    }

    public function testRedisInstrumentationValidationFailsIfExtensionMissing(): void
    {
        $this->extension->extensionOverrides['redis'] = false;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The "redis" extension is required for Redis instrumentation with type "redis".');

        $container = new ContainerBuilder;
        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
    }

    public function testRedisInstrumentationValidationFailsIfPredisMissing(): void
    {
        $this->extension->interfaceOverrides[ClientInterface::class] = false;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The "predis/predis" package is required for Redis instrumentation with type "predis".');

        $container = new ContainerBuilder;
        $config = [
            'instrumentation' => [
                'predis' => [
                    'enabled' => true
                ]
            ]
        ];

        $this->extension->load([$config], $container);
    }

    public function testRedisInstrumentationFailsIfNoServicesFound(): void
    {
        $container = new ContainerBuilder;
        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => true
                ],
                'predis' => [
                    'enabled' => false
                ]
            ]
        ];

        $this->extension->load([$config], $container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Redis services were found in the container');

        $this->compilerPass->process($container);
    }

    public function testRedisInstrumentationFailsIfWrongTypeServicesFound(): void
    {
        $container = new ContainerBuilder;
        $container->setDefinition('predis_service', new Definition(MockPredisClient::class));

        $config = [
            'instrumentation' => [
                'redis' => [
                    'enabled' => true
                ],
                'predis' => [
                    'enabled' => false
                ]
            ]
        ];

        $this->extension->load([$config], $container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('but no such services were found. However, 1 services of type "predis" were found.');

        $this->compilerPass->process($container);
    }
}
