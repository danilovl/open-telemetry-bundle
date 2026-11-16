<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\{
    OpenTelemetryCompilerPass,
    OpenTelemetryExtension
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\TracingDbalMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\{
    PhpRedisHookInstrumentation,
    PredisHookInstrumentation,
    RedisHookSubscriber
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Redis\Interfaces\RedisMetricsInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpClient\HttpTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\HttpRequestTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\MailerTracingSubscriber;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\MessageBusTracingMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\TraceableTwigExtension;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\UrlHelper;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\DependencyInjection\TestableOpenTelemetryExtension;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyRedisMetrics;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\{
    ContainerBuilder,
    Definition,
    Reference
};

class DependencyResolutionTest extends TestCase
{
    private TestableOpenTelemetryExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new TestableOpenTelemetryExtension;
        $this->extension->hookApiOverride = true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $this->extension->load([$config], $container);

        return $container;
    }

    private function isRegistered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    private function simulateNothingInstalled(): void
    {
        $this->extension->hookApiOverride = false;
        $this->extension->extensionOverrides = ['redis' => false];
        $this->extension->interfaceOverrides = [
            'Predis\ClientInterface' => false,
            'Symfony\Component\Messenger\MessageBusInterface' => false,
            'Doctrine\DBAL\Driver' => false,
            'Symfony\Component\Mailer\MailerInterface' => false,
            'Symfony\Contracts\HttpClient\HttpClientInterface' => false,
        ];
        $this->extension->classOverrides = [
            'Twig\Environment' => false,
            'Danilovl\AsyncBundle\AsyncBundle' => false,
            'Symfony\Component\Console\Application' => false,
        ];
    }

    public function testDefaultConfigWithoutOptionalPackages(): void
    {
        $this->simulateNothingInstalled();

        $container = $this->load(['instrumentation' => ['http_server' => ['enabled' => true]]]);

        $this->assertTrue($this->isRegistered($container, HttpRequestTracingSubscriber::class));
        $this->assertFalse($this->isRegistered($container, PredisHookInstrumentation::class));
        $this->assertFalse($this->isRegistered($container, PhpRedisHookInstrumentation::class));
        $this->assertFalse($this->isRegistered($container, MessageBusTracingMiddleware::class));
        $this->assertFalse($this->isRegistered($container, TracingDbalMiddleware::class));
        $this->assertFalse($this->isRegistered($container, MailerTracingSubscriber::class));
        $this->assertFalse($this->isRegistered($container, TraceableTwigExtension::class));
        $this->assertFalse($this->isRegistered($container, HttpTracingMiddleware::class));
    }

    public function testInstalledPackagesAutoInstrumented(): void
    {
        $container = $this->load();

        $this->assertTrue($this->isRegistered($container, MessageBusTracingMiddleware::class));
        $this->assertTrue($this->isRegistered($container, TracingDbalMiddleware::class));
        $this->assertTrue($this->isRegistered($container, HttpTracingMiddleware::class));
        $this->assertTrue($this->isRegistered($container, PredisHookInstrumentation::class));
        $this->assertTrue($this->isRegistered($container, RedisHookSubscriber::class));
    }

    /**
     * @param array<string, bool> $interfaces
     * @param array<string, bool> $classes
     * @param array<string, bool> $extensions
     */
    #[DataProvider('provideEnabledWithMissingDependencyFailsCases')]
    public function testEnabledWithMissingDependencyFails(
        string $key,
        string $expectedMessage,
        array $interfaces = [],
        array $classes = [],
        array $extensions = [],
        ?bool $hookApi = true,
    ): void {
        $this->extension->interfaceOverrides = $interfaces;
        $this->extension->classOverrides = $classes;
        $this->extension->extensionOverrides = $extensions;
        $this->extension->hookApiOverride = $hookApi;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->load(['instrumentation' => [$key => ['enabled' => true]]]);
    }

    public static function provideEnabledWithMissingDependencyFailsCases(): Generator
    {
        yield 'predis package missing' => ['predis', 'predis/predis', ['Predis\ClientInterface' => false]];
        yield 'predis without ext-opentelemetry' => ['predis', 'opentelemetry', [], [], [], false];
        yield 'redis extension missing' => ['redis', '"redis"', [], [], ['redis' => false]];
        yield 'redis without ext-opentelemetry' => ['redis', 'opentelemetry', [], [], ['redis' => true], false];
        yield 'messenger missing' => ['messenger', 'symfony/messenger', ['Symfony\Component\Messenger\MessageBusInterface' => false]];
        yield 'doctrine missing' => ['doctrine', 'doctrine/dbal', ['Doctrine\DBAL\Driver' => false]];
        yield 'twig missing' => ['twig', 'twig/twig', [], ['Twig\Environment' => false]];
        yield 'console missing' => ['console', 'symfony/console', [], ['Symfony\Component\Console\Application' => false]];
        yield 'async missing' => ['async', 'async-bundle', [], ['Danilovl\AsyncBundle\AsyncBundle' => false]];
        yield 'mailer missing' => ['mailer', 'symfony/mailer', ['Symfony\Component\Mailer\MailerInterface' => false]];
        yield 'http client missing' => ['http_client', 'symfony/http-client', ['Symfony\Contracts\HttpClient\HttpClientInterface' => false]];
    }

    public function testHttpClientContractsOnlyNotEnough(): void
    {
        $this->extension->traitOverrides = ['Symfony\Component\HttpClient\AsyncDecoratorTrait' => false];

        $container = $this->load();

        $this->assertFalse($this->isRegistered($container, HttpTracingMiddleware::class));
    }

    public function testDisabledInstrumentationNotRegistered(): void
    {
        $container = $this->load(['instrumentation' => ['messenger' => ['enabled' => false]]]);

        $this->assertFalse($this->isRegistered($container, MessageBusTracingMiddleware::class));
    }

    /**
     * @return array{0: Definition, 1: bool}
     */
    private function scope(ContainerBuilder $container, string $key): array
    {
        $definition = $container->getDefinition(OpenTelemetryExtension::scopeServiceId($key));
        $this->assertSame(ScopedInstrumentation::class, $definition->getClass());

        /** @var bool $tracingEnabled */
        $tracingEnabled = $definition->getArgument(1);

        return [$definition, $tracingEnabled];
    }

    public function testTracingOffMeteringOnUsesNoopTracer(): void
    {
        $container = $this->load([
            'instrumentation' => [
                'http_server' => ['tracing' => ['enabled' => false], 'metering' => ['enabled' => true]],
            ],
        ]);

        $this->assertTrue($this->isRegistered($container, HttpRequestTracingSubscriber::class));
        [, $tracingEnabled] = $this->scope($container, 'http_server');
        $this->assertFalse($tracingEnabled);

        [, $doctrineTracing] = $this->scope($container, 'doctrine');
        $this->assertTrue($doctrineTracing);
    }

    public function testEveryInstrumentationGetsItsOwnScope(): void
    {
        $container = $this->load();

        foreach (OpenTelemetryExtension::INSTRUMENTATION_SCOPES as $key => $name) {
            $definition = $container->getDefinition(OpenTelemetryExtension::scopeServiceId($key));
            /** @var Definition $cached */
            $cached = $definition->getArgument(0);
            $this->assertSame($name, $cached->getArgument(0));
        }
    }

    public function testRedisAndPredisMetricsIndependent(): void
    {
        $container = $this->load([
            'instrumentation' => [
                'redis' => ['enabled' => true, 'metering' => ['enabled' => true]],
                'predis' => ['enabled' => true, 'metering' => ['enabled' => false]],
            ],
        ]);

        $redisDefault = $container->getDefinition(OpenTelemetryExtension::defaultMetricsServiceId('redis'));
        $predisDefault = $container->getDefinition(OpenTelemetryExtension::defaultMetricsServiceId('predis'));

        $this->assertTrue($redisDefault->getArgument('$isEnable'));
        $this->assertFalse($predisDefault->getArgument('$isEnable'), 'predis must not inherit the metering flag of redis');

        $redisArgument = $container->getDefinition(PhpRedisHookInstrumentation::class)->getArgument('$redisMetrics');
        $predisArgument = $container->getDefinition(PredisHookInstrumentation::class)->getArgument('$redisMetrics');
        $this->assertInstanceOf(Reference::class, $redisArgument);
        $this->assertInstanceOf(Reference::class, $predisArgument);

        $this->assertSame(RedisMetricsInterface::class, (string) $redisArgument);
        $this->assertSame(OpenTelemetryExtension::defaultMetricsServiceId('predis'), (string) $predisArgument);
    }

    public function testCustomRedisMetricsUsedByBoth(): void
    {
        $container = $this->load([
            'instrumentation' => ['redis' => ['enabled' => true], 'predis' => ['enabled' => true]],
        ]);
        $container->register('app.redis_metrics', SpyRedisMetrics::class);

        new OpenTelemetryCompilerPass()->process($container);

        $this->assertSame('app.redis_metrics', (string) $container->getAlias(RedisMetricsInterface::class));

        foreach ([PhpRedisHookInstrumentation::class, PredisHookInstrumentation::class] as $serviceId) {
            /** @var Reference $argument */
            $argument = $container->getDefinition($serviceId)->getArgument('$redisMetrics');
            $this->assertSame(RedisMetricsInterface::class, (string) $argument, $serviceId);
        }
    }

    public function testExplicitRedisMetricsAlias(): void
    {
        $container = $this->load([
            'instrumentation' => ['redis' => ['enabled' => true], 'predis' => ['enabled' => true]],
        ]);
        $container->register('app.metrics_a', SpyRedisMetrics::class);
        $container->register('app.metrics_b', SpyRedisMetrics::class);
        $container->setAlias(RedisMetricsInterface::class, 'app.metrics_b');

        new OpenTelemetryCompilerPass()->process($container);

        $this->assertSame('app.metrics_b', (string) $container->getAlias(RedisMetricsInterface::class));
        $argument = $container->getDefinition(PredisHookInstrumentation::class)->getArgument('$redisMetrics');
        $this->assertInstanceOf(Reference::class, $argument);
        $this->assertSame(RedisMetricsInterface::class, (string) $argument);
    }

    public function testPrivacyDefaults(): void
    {
        $container = $this->load();

        $this->assertSame(UrlHelper::DEFAULT_SENSITIVE_QUERY_PARAMETERS, $container->getParameter(OpenTelemetryExtension::PARAMETER_SENSITIVE_QUERY_PARAMETERS));
        $this->assertTrue($container->getParameter(OpenTelemetryExtension::PARAMETER_SANITIZE_SQL));
    }

    public function testPrivacyConfigPassed(): void
    {
        $container = $this->load([
            'privacy' => ['sensitive_query_parameters' => ['order_id'], 'sanitize_sql' => false],
        ]);

        $this->assertSame(['order_id'], $container->getParameter(OpenTelemetryExtension::PARAMETER_SENSITIVE_QUERY_PARAMETERS));
        $this->assertFalse($container->getParameter(OpenTelemetryExtension::PARAMETER_SANITIZE_SQL));

        $parameter = '%' . OpenTelemetryExtension::PARAMETER_SENSITIVE_QUERY_PARAMETERS . '%';
        $this->assertSame($parameter, $container->getDefinition(HttpRequestTracingSubscriber::class)->getArgument('$sensitiveQueryParameters'));
        $this->assertSame($parameter, $container->getDefinition(HttpTracingMiddleware::class)->getArgument('$sensitiveQueryParameters'));
        $this->assertSame(
            '%' . OpenTelemetryExtension::PARAMETER_SANITIZE_SQL . '%',
            $container->getDefinition(TracingDbalMiddleware::class)->getArgument('$sanitizeSql')
        );
    }
}
