<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Instrumentation;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\HookRegistry;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyHookFunction;
use PHPUnit\Framework\TestCase;

class HookRegistryTest extends TestCase
{
    private SpyHookFunction $spy;

    protected function setUp(): void
    {
        $this->spy = SpyHookFunction::install();
    }

    protected function tearDown(): void
    {
        SpyHookFunction::uninstall();
    }

    public function testMethodIsHookedOncePerOwner(): void
    {
        $noop = static function (): void {};

        $this->assertTrue(HookRegistry::hook('a', 'Foo', 'bar', $noop, $noop));
        $this->assertFalse(HookRegistry::hook('a', 'Foo', 'bar', $noop, $noop));
        $this->assertTrue(HookRegistry::hook('b', 'Foo', 'bar', $noop, $noop));

        $this->assertSame(2, $this->spy->count('Foo::bar'));
    }
}
