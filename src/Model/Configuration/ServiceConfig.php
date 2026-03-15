<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

/**
 * @phpstan-type ServiceConfigArray array{
 *     namespace: string|null,
 *     name: string|null,
 *     version: string|null,
 *     environment: string|null
 * }
 */
final readonly class ServiceConfig
{
    public function __construct(
        public ?string $namespace,
        public ?string $name,
        public ?string $version,
        public ?string $environment
    ) {}

    /**
     * @phpstan-import-type ServiceConfigArray from ServiceConfig
     * @param ServiceConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            namespace: $config['namespace'],
            name: $config['name'],
            version: $config['version'],
            environment: $config['environment']
        );
    }
}
