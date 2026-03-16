<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;

class TestableOpenTelemetryExtension extends OpenTelemetryExtension
{
    /** @var array<string, bool> */
    public array $extensionOverrides = [];

    /** @var array<string, bool> */
    public array $interfaceOverrides = [];

    protected function extensionLoaded(string $name): bool
    {
        return $this->extensionOverrides[$name] ?? parent::extensionLoaded($name);
    }

    protected function interfaceExists(string $name, bool $autoload = true): bool
    {
        return $this->interfaceOverrides[$name] ?? parent::interfaceExists($name, $autoload);
    }
}
