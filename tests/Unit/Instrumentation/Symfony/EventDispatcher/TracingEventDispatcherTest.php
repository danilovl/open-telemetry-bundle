<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\EventDispatcher;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\EventDispatcher\TracingEventDispatcher;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TracingEventDispatcherTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private function dispatchInsideParent(object $event): void
    {
        $inner = new EventDispatcher;
        $instrumentation = ScopedInstrumentation::create(TracingEventDispatcher::INSTRUMENTATION_NAME);
        $dispatcher = new TracingEventDispatcher($inner, $instrumentation);

        $parent = $this->telemetry->tracerProvider->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $dispatcher->dispatch($event);
        } finally {
            $scope->detach();
            $parent->end();
        }
    }

    public function testRegularEventIsTraced(): void
    {
        $this->dispatchInsideParent(new stdClass);

        $this->assertSame(['event dispatch', 'parent'], $this->telemetry->spanNames());
    }

    public function testKernelTerminateIsNotTraced(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request;
        $response = new Response;
        $event = new TerminateEvent($kernel, $request, $response);

        $this->dispatchInsideParent($event);

        $this->assertSame(['parent'], $this->telemetry->spanNames());
    }

    public function testConsoleTerminateIsNotTraced(): void
    {
        $command = new Command('app:test');
        $input = new ArrayInput([]);
        $output = new NullOutput;
        $event = new ConsoleTerminateEvent($command, $input, $output, 0);

        $this->dispatchInsideParent($event);

        $this->assertSame(['parent'], $this->telemetry->spanNames());
    }
}
