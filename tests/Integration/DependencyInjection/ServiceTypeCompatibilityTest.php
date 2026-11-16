<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\{
    OpenTelemetryCompilerPass,
    TraceableHookCompilerPass
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\DependencyInjection\TestableOpenTelemetryExtension;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;
use Redis;
use Symfony\Component\DependencyInjection\{
    ContainerBuilder,
    Reference
};
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

class ServiceTypeCompatibilityTest extends TestCase
{
    /**
     * @param array<string, mixed> $instrumentation
     */
    private function compile(array $instrumentation, callable $configure): ContainerBuilder
    {
        $extension = new TestableOpenTelemetryExtension;
        $extension->hookApiOverride = true;

        $container = new ContainerBuilder;
        $container->registerExtension($extension);
        $container->loadFromExtension('danilovl_open_telemetry', [
            'instrumentation' => $instrumentation + [
                'events' => ['enabled' => false],
                'cache' => ['enabled' => false],
            ],
        ]);
        $container->addCompilerPass(new OpenTelemetryCompilerPass);
        $container->addCompilerPass(new TraceableHookCompilerPass);

        $configure($container);
        $container->compile();

        return $container;
    }

    public function testRedisServicesKeepTheirType(): void
    {
        if (!class_exists(Redis::class)) {
            $this->markTestSkipped('ext-redis is not loaded');
        }

        $container = $this->compile(
            ['redis' => ['enabled' => true], 'predis' => ['enabled' => false]],
            static function (ContainerBuilder $container): void {
                $redis = new Reference('app.redis');

                $container->register('app.redis', Redis::class);
                $container->register('app.session_handler', RedisSessionHandler::class)
                    ->setArguments([$redis])
                    ->setPublic(true);
                $container->register('app.redis_consumer', RedisConsumer::class)
                    ->setArguments([$redis])
                    ->setPublic(true);
            }
        );

        $this->assertInstanceOf(RedisSessionHandler::class, $container->get('app.session_handler'));
        $this->assertInstanceOf(RedisConsumer::class, $container->get('app.redis_consumer'));
    }

    public function testRedisWithoutRedisServices(): void
    {
        $container = $this->compile(['redis' => ['enabled' => true], 'predis' => ['enabled' => true]], static function (): void {});

        $this->assertTrue($container->isCompiled());
    }

    public function testPredisClientKeepsItsConcreteType(): void
    {
        $container = $this->compile(
            ['predis' => ['enabled' => true], 'redis' => ['enabled' => false]],
            static function (ContainerBuilder $container): void {
                $predis = new Reference('app.predis');

                $container->register('app.predis', PredisClient::class)
                    ->setArguments(['tcp://127.0.0.1:6379']);
                $container->register('app.predis_consumer', PredisConsumer::class)
                    ->setArguments([$predis])
                    ->setPublic(true);
                $container->register('app.predis_session_handler', RedisSessionHandler::class)
                    ->setArguments([$predis])
                    ->setPublic(true);
            }
        );

        $this->assertInstanceOf(PredisConsumer::class, $container->get('app.predis_consumer'));
        $this->assertInstanceOf(RedisSessionHandler::class, $container->get('app.predis_session_handler'));
    }
}

final readonly class RedisConsumer
{
    public function __construct(public Redis $redis) {}
}

final readonly class PredisConsumer
{
    public function __construct(public PredisClient $client) {}
}
