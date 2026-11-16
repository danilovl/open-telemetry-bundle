<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Closure;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\HookRegistry;
use Throwable;

/** Replaces ext-opentelemetry hook() and lets tests call the registered hooks. */
final class SpyHookFunction
{
    /** @var array<string, list<array{pre: Closure, post: Closure}>> */
    public array $hooks = [];

    public static function install(): self
    {
        $spy = new self;
        HookRegistry::setHookFunction(static function (string $class, string $method, Closure $pre, Closure $post) use ($spy): void {
            $spy->hooks[$class . '::' . $method][] = ['pre' => $pre, 'post' => $post];
        });

        return $spy;
    }

    public static function uninstall(): void
    {
        HookRegistry::setHookFunction(null);
    }

    public function count(string $target): int
    {
        return count($this->hooks[$target] ?? []);
    }

    /**
     * Simulates a hooked call, as ext-opentelemetry passes the arguments.
     *
     * @param array<int, mixed> $params
     */
    public function call(
        string $class,
        string $method,
        array $params = [],
        mixed $return = null,
        ?Throwable $exception = null,
        mixed $object = null,
    ): void {
        foreach ($this->hooks[$class . '::' . $method] ?? [] as $hook) {
            ($hook['pre'])($object, $params, $class, $method, null, null);
        }

        foreach (array_reverse($this->hooks[$class . '::' . $method] ?? []) as $hook) {
            ($hook['post'])($object, $params, $return, $exception);
        }
    }
}
