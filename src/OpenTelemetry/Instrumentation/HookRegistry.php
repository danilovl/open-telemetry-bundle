<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation;

use Closure;

/**
 * ext-opentelemetry hooks cannot be removed: every class::method is hooked once per process.
 * Callers delegate from the hook to their current instance, so a kernel reboot neither duplicates spans
 * nor keeps using the services of the old container.
 */
final class HookRegistry
{
    /** @var (Closure(string, string, Closure, Closure): mixed)|null */
    private static ?Closure $hookFunction = null;

    /** @var array<string, true> */
    private static array $hooked = [];

    public static function isAvailable(): bool
    {
        return self::$hookFunction !== null || function_exists('OpenTelemetry\\Instrumentation\\hook');
    }

    /**
     * @param string $owner separates hooks of different instrumentations on the same method
     * @return bool false when the hook API is missing or the method is already hooked by this owner
     */
    public static function hook(string $owner, string $class, string $method, Closure $pre, Closure $post): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $key = $owner . '|' . $class . '::' . $method;
        if (isset(self::$hooked[$key])) {
            return false;
        }

        self::$hooked[$key] = true;

        if (self::$hookFunction !== null) {
            (self::$hookFunction)($class, $method, $pre, $post);
        } else {
            \OpenTelemetry\Instrumentation\hook(class: $class, function: $method, pre: $pre, post: $post);
        }

        return true;
    }

    /**
     * @internal tests only: replaces hook() and forgets registered hooks
     *
     * @param (Closure(string, string, Closure, Closure): mixed)|null $hookFunction
     */
    public static function setHookFunction(?Closure $hookFunction): void
    {
        self::$hookFunction = $hookFunction;
        self::$hooked = [];
    }
}
