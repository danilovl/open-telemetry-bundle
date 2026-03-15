<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Resource;

use Danilovl\OpenTelemetryBundle\Model\Configuration\ServiceConfig;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SDK\Resource\{
    ResourceInfo,
    ResourceInfoFactory};
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * @phpstan-import-type ServiceConfigArray from ServiceConfig
 */
final readonly class DefaultResourceInfoFactory
{
    /**
     * @phpstan-param ServiceConfigArray $serviceConfig
     */
    public function __construct(private array $serviceConfig) {}

    public function createResource(): ResourceInfo
    {
        $serviceConfig = ServiceConfig::fromConfig($this->serviceConfig);
        $resource = ResourceInfoFactory::defaultResource();
        $attributes = [];

        if (is_string($serviceConfig->name) && $serviceConfig->name !== '') {
            $attributes[ServiceAttributes::SERVICE_NAME] = $serviceConfig->name;
        }

        if (is_string($serviceConfig->namespace) && $serviceConfig->namespace !== '') {
            $attributes[ResourceAttributes::SERVICE_NAMESPACE] = $serviceConfig->namespace;
        }

        if (is_string($serviceConfig->version) && $serviceConfig->version !== '') {
            $attributes[ServiceAttributes::SERVICE_VERSION] = $serviceConfig->version;
        }

        if (is_string($serviceConfig->environment) && $serviceConfig->environment !== '') {
            $attributes[ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME] = $serviceConfig->environment;
        }

        if ($attributes === []) {
            return $resource;
        }

        return $resource->merge(ResourceInfo::create(Attributes::create($attributes)));
    }
}
