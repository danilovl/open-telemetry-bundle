<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\DependencyInjection;

use Danilovl\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;

class TestableOpenTelemetryExtension extends OpenTelemetryExtension
{
    /** @var array<string, bool> */
    public array $extensionOverrides = [];

    /** @var array<string, bool> */
    public array $interfaceOverrides = [];

    /** @var array<string, bool> */
    public array $classOverrides = [];

    /** @var array<string, bool> */
    public array $traitOverrides = [];

    public ?bool $hookApiOverride = null;

    protected function extensionLoaded(string $name): bool
    {
        return $this->extensionOverrides[$name] ?? parent::extensionLoaded($name);
    }

    protected function interfaceExists(string $name, bool $autoload = true): bool
    {
        return $this->interfaceOverrides[$name] ?? parent::interfaceExists($name, $autoload);
    }

    protected function classExists(string $name, bool $autoload = true): bool
    {
        return $this->classOverrides[$name] ?? parent::classExists($name, $autoload);
    }

    protected function traitExists(string $name, bool $autoload = true): bool
    {
        return $this->traitOverrides[$name] ?? parent::traitExists($name, $autoload);
    }

    protected function hookApiAvailable(): bool
    {
        return $this->hookApiOverride ?? parent::hookApiAvailable();
    }
}
