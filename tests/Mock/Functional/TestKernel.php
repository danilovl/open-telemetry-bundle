<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Log\DefaultLoggerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Metric\DefaultMeterProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\OpenTelemetryBundle;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\{
    MockMeterProviderFactory,
    MockLoggerProviderFactory,
    RecordingTracerProviderFactory
};
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Controller\TestController;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Command\{
    ErrorCommand,
    MessengerConsumeCommand,
    SimpleCommand,
    TestCommand
};

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/open_telemetry_bundle_functional_test';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new OpenTelemetryBundle;
    }

    // MicroKernelTrait
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => [
                'resource' => 'kernel::loadRoutes',
                'type' => 'service',
            ],
        ]);

        $container->register(TestController::class)
            ->addTag('controller.service_arguments')
            ->setPublic(true);

        $container->register(TestCommand::class)
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(SimpleCommand::class)
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(ErrorCommand::class)
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(MessengerConsumeCommand::class)
            ->addTag('console.command')
            ->setPublic(true);
    }

    // MicroKernelTrait
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('home', '/')
            ->controller([TestController::class, 'home']);

        $routes->add('api_users', '/api/users')
            ->controller([TestController::class, 'apiUsers']);

        $routes->add('api_attributes', '/api/attributes')
            ->controller([TestController::class, 'apiAttributes']);

        $routes->add('_wdt', '/_wdt/{token}')
            ->controller([TestController::class, 'wdtToolbar'])
            ->requirements(['token' => '.*'])
            ->defaults(['token' => null]);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition(DefaultTracerProviderFactory::class)) {
                    $container->getDefinition(DefaultTracerProviderFactory::class)
                        ->setClass(RecordingTracerProviderFactory::class)
                        ->setArguments([])
                        ->setPublic(true);
                }

                if ($container->hasDefinition(DefaultMeterProviderFactory::class)) {
                    $container->getDefinition(DefaultMeterProviderFactory::class)
                        ->setClass(MockMeterProviderFactory::class)
                        ->setArguments([])
                        ->setPublic(true);
                }

                if ($container->hasDefinition(DefaultLoggerProviderFactory::class)) {
                    $container->getDefinition(DefaultLoggerProviderFactory::class)
                        ->setClass(MockLoggerProviderFactory::class)
                        ->setArguments([])
                        ->setPublic(true);
                }

                if ($container->hasDefinition('danilovl.open_telemetry.cached_instrumentation')) {
                    $container->getDefinition('danilovl.open_telemetry.cached_instrumentation')
                        ->setPublic(true);
                }

                if ($container->hasDefinition('event_dispatcher')) {
                    $container->getDefinition('event_dispatcher')->setPublic(true);
                }

                if ($container->hasDefinition('console.error_listener')) {
                    $container->getDefinition('console.error_listener')
                        ->setPublic(true)
                        ->setArgument(0, null);
                }
            }
        });
    }
}
