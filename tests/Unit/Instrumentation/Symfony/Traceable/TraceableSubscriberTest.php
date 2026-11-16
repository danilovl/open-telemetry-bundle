<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Traceable;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\TraceableSubscriber;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{
    Request,
    Response
};
use Symfony\Component\HttpKernel\Event\{
    ControllerEvent,
    TerminateEvent
};
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TraceableSubscriberTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private function handle(callable $controller): void
    {
        $instrumentation = ScopedInstrumentation::create(TraceableSubscriber::INSTRUMENTATION_NAME);
        $subscriber = new TraceableSubscriber($instrumentation);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request;
        $response = new Response;

        $controllerEvent = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        $terminateEvent = new TerminateEvent($kernel, $request, $response);

        $subscriber->onController($controllerEvent);
        $subscriber->onHttpTerminate($terminateEvent);
    }

    public function testInvokableControllerIsTraced(): void
    {
        $this->handle(new TraceableInvokableController);

        $this->assertSame(['invokable'], $this->telemetry->spanNames());
    }

    public function testMethodAttributeWins(): void
    {
        $this->handle([new TraceableMixedController, 'action']);

        $this->assertSame(['action'], $this->telemetry->spanNames());
    }

    public function testClassAttributeFallback(): void
    {
        $this->handle([new TraceableClassController, 'action']);

        $this->assertSame(['class'], $this->telemetry->spanNames());
    }

    public function testClosureControllerIsIgnored(): void
    {
        $this->handle(static fn (): Response => new Response);

        $this->assertSame([], $this->telemetry->spanNames());
    }
}

#[Traceable(name: 'invokable', handler: TraceableHandler::CONTROLLER)]
final class TraceableInvokableController
{
    public function __invoke(): Response
    {
        return new Response;
    }
}

/** Class-level HOOK must not hide the method-level CONTROLLER attribute. */
#[Traceable]
final class TraceableMixedController
{
    #[Traceable(name: 'action', handler: TraceableHandler::CONTROLLER)]
    public function action(): Response
    {
        return new Response;
    }
}

#[Traceable(name: 'class', handler: TraceableHandler::CONTROLLER)]
final class TraceableClassController
{
    public function action(): Response
    {
        return new Response;
    }
}
