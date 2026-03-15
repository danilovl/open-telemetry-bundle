<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Model\Configuration;

use Danilovl\OpenTelemetryBundle\Model\Configuration\ServiceConfig;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type ServiceConfigArray from ServiceConfig */
class ServiceConfigTest extends TestCase
{
    /**
     * @phpstan-param ServiceConfigArray $config
     */
    #[DataProvider('provideFromConfigCases')]
    public function testFromConfig(
        array $config,
        ?string $namespace,
        ?string $name,
        ?string $version,
        ?string $environment
    ): void {
        $serviceConfig = ServiceConfig::fromConfig($config);

        $this->assertSame($namespace, $serviceConfig->namespace);
        $this->assertSame($name, $serviceConfig->name);
        $this->assertSame($version, $serviceConfig->version);
        $this->assertSame($environment, $serviceConfig->environment);
    }

    public function testConstructor(): void
    {
        $config = new ServiceConfig('ns', 'svc', '3.0', 'dev');

        $this->assertSame('ns', $config->namespace);
        $this->assertSame('svc', $config->name);
        $this->assertSame('3.0', $config->version);
        $this->assertSame('dev', $config->environment);
    }

    public static function provideFromConfigCases(): Generator
    {
        yield 'all filled' => [
            ['namespace' => 'my.app', 'name' => 'api', 'version' => '1.0.0', 'environment' => 'prod'],
            'my.app', 'api', '1.0.0', 'prod',
        ];
        yield 'all null' => [
            ['namespace' => null, 'name' => null, 'version' => null, 'environment' => null],
            null, null, null, null,
        ];
        yield 'partial filled' => [
            ['namespace' => 'app', 'name' => null, 'version' => '2.0', 'environment' => null],
            'app', null, '2.0', null,
        ];
    }
}
