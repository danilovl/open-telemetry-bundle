<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Traceable;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Traceable\TraceableHookSubscriber;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyHookFunction;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class TraceableHookSubscriberTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private const string SERVICE = 'App\\Service\\ReportService';

    private SpyHookFunction $spy;

    protected function setUp(): void
    {
        $this->spy = SpyHookFunction::install();
    }

    protected function tearDown(): void
    {
        SpyHookFunction::uninstall();
    }

    private function boot(?string $name = 'report.build'): TraceableHookSubscriber
    {
        $hook = [
            'class' => self::SERVICE,
            'method' => 'build',
            'name' => $name,
            'attributes' => ['report.kind' => 'daily'],
        ];

        $subscriber = new TraceableHookSubscriber(
            instrumentation: ScopedInstrumentation::create(TraceableHookSubscriber::INSTRUMENTATION_NAME),
            hooks: [$hook],
        );

        $command = new Command('app:test');
        $input = new ArrayInput([]);
        $output = new NullOutput;
        $event = new ConsoleCommandEvent($command, $input, $output);
        $subscriber->initializeHooksForConsole($event);

        return $subscriber;
    }

    public function testHookedCallCreatesSpan(): void
    {
        $subscriber = $this->boot();

        $this->spy->call(self::SERVICE, 'build');

        $span = $this->telemetry->span('report.build');
        $this->assertSame('daily', $span->getAttributes()->get('report.kind'));
        $this->assertSame(0, $subscriber->getOpenSpanCount());
    }

    public function testExceptionIsRecorded(): void
    {
        $this->boot();

        $this->spy->call(self::SERVICE, 'build', exception: new RuntimeException('boom'));

        $this->assertSame(StatusCode::STATUS_ERROR, $this->telemetry->span('report.build')->getStatus()->getCode());
    }

    public function testKernelRebootDoesNotDuplicateSpans(): void
    {
        $this->boot('old');
        $this->boot('new');

        $this->assertSame(1, $this->spy->count(self::SERVICE . '::build'));

        $this->spy->call(self::SERVICE, 'build');

        $this->assertSame(['new'], $this->telemetry->spanNames());
    }
}
