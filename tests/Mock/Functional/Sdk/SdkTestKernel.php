<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\DefaultLoggerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetryBundle;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\MockLoggerProviderFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SdkTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(private readonly array $bundleConfig = [])
    {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/otel_bundle_sdk_kernel';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/cache/' . md5(serialize($this->bundleConfig) . filemtime(__FILE__));
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new OpenTelemetryBundle;
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['resource' => 'kernel::loadRoutes', 'type' => 'service'],
            'cache' => ['app' => 'cache.adapter.array'],
            'http_client' => ['mock_response_factory' => SdkMockResponseFactory::class],
            'messenger' => [
                'transports' => ['async' => 'in-memory://?serialize=true'],
                'routing' => [SdkTestMessage::class => 'async'],
            ],
        ]);

        $container->loadFromExtension('danilovl_open_telemetry', $this->bundleConfig + [
            'service' => ['name' => 'sdk-test-app'],
        ]);

        $container->register('logger', NullLogger::class);

        $container->register(SdkMockResponseFactory::class)->setPublic(true);
        $container->register(SdkTestMessageHandler::class)
            ->addTag('messenger.message_handler')
            ->setPublic(true);

        $container->register(SdkTestController::class)
            ->setAutowired(true)
            ->addTag('controller.service_arguments')
            ->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('home', '/')->controller([SdkTestController::class, 'home']);
        $routes->add('user_show', '/users/{id}')->controller([SdkTestController::class, 'user']);
        $routes->add('error', '/error')->controller([SdkTestController::class, 'error']);
        $routes->add('not_found', '/not-found')->controller([SdkTestController::class, 'notFound']);
        $routes->add('service_unavailable', '/unavailable')->controller([SdkTestController::class, 'serverErrorResponse']);
        $routes->add('cache', '/cache')->controller([SdkTestController::class, 'cache']);
        $routes->add('outgoing', '/outgoing')->controller([SdkTestController::class, 'outgoing']);
        $routes->add('dispatch', '/dispatch')->controller([SdkTestController::class, 'dispatch']);
        $routes->add('traceable', '/traceable')->controller([SdkTestController::class, 'traceable']);
        $routes->add('_wdt', '/_wdt/{token}')->controller([SdkTestController::class, 'home']);
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(DefaultTracerProviderFactory::class)
                    ->setClass(InMemoryTracerProviderFactory::class)
                    ->setPublic(true);

                $container->getDefinition(DefaultMeterProviderFactory::class)
                    ->setClass(InMemoryMeterProviderFactory::class)
                    ->setArguments([])
                    ->setPublic(true);

                $container->getDefinition(DefaultLoggerProviderFactory::class)
                    ->setClass(MockLoggerProviderFactory::class)
                    ->setArguments([]);

                foreach (['messenger.transport.async', 'messenger.default_bus', 'cache.app'] as $id) {
                    if ($container->hasDefinition($id)) {
                        $container->getDefinition($id)->setPublic(true);
                    }
                }

                $container->setAlias('test.messenger.bus', 'messenger.default_bus')->setPublic(true);
            }
        });
    }
}
