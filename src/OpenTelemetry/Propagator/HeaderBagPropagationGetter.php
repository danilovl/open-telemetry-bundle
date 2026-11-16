<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Propagator;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Symfony\Component\HttpFoundation\HeaderBag;

final class HeaderBagPropagationGetter implements PropagationGetterInterface
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param mixed $carrier
     * @return list<string>
     */
    public function keys($carrier): array
    {
        if (!$carrier instanceof HeaderBag) {
            return [];
        }

        return array_map('strval', array_keys($carrier->all()));
    }

    /**
     * @param mixed $carrier
     */
    public function get($carrier, string $key): ?string
    {
        if (!$carrier instanceof HeaderBag) {
            return null;
        }

        $values = $carrier->all($key);

        return $values === [] ? null : implode(',', array_map('strval', $values));
    }
}
