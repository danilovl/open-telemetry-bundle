<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle;

use Danilovl\OpenTelemetryBundle\DependencyInjection\{
    OpenTelemetryExtension,
    OpenTelemetryCompilerPass,
    TraceableHookCompilerPass
};
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class OpenTelemetryBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OpenTelemetryCompilerPass);
        $container->addCompilerPass(new TraceableHookCompilerPass);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new OpenTelemetryExtension;
    }
}
