<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Functional\Console;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Trace\DefaultTracerProviderFactory;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Provider\{
    RecordingTracerProvider,
    RecordingTracerProviderFactory
};
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\TestKernel;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Trace\RecordingSpan;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Throwable;

class ConsoleCommandTracingTest extends TestCase
{
    protected static TestKernel $kernel;

    private RecordingTracerProvider $provider;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel;
        static::$kernel->boot();
    }

    protected function setUp(): void
    {
        /** @var RecordingTracerProviderFactory $factory */
        $factory = static::$kernel->getContainer()->get(DefaultTracerProviderFactory::class);

        $this->provider = $factory->getProvider();
        $this->provider->reset();
    }

    private function findSpan(string $name): ?RecordingSpan
    {
        return array_find($this->provider->getSpans(), static fn (RecordingSpan $span): bool => $span->getName() === $name);
    }

    public function testConsoleCommandTraceableSpan(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'test:traceable']);

        $consoleSpan = $this->findSpan('console test:traceable');
        $this->assertNotNull($consoleSpan, 'Console span "console test:traceable" was not created');
        $this->assertTrue($consoleSpan->isEnded());

        $consoleAttrs = $consoleSpan->getAttributes();
        $this->assertSame('cli', $consoleAttrs['transaction.type'] ?? null);
        $this->assertSame('console', $consoleAttrs['console.system'] ?? null);
        $this->assertSame('test:traceable', $consoleAttrs['console.command'] ?? null);
        $this->assertSame('test:traceable', $consoleAttrs['console.command.name'] ?? null);

        /** @var string $commandClass */
        $commandClass = $consoleAttrs['console.command.class'] ?? '';
        $this->assertStringContainsString('TestCommand', $commandClass);
        $this->assertSame(0, $consoleAttrs['console.command.exit_code'] ?? null);

        $traceableSpan = $this->findSpan('console.test_command');

        $this->assertNotNull($traceableSpan, 'Traceable span "console.test_command" was not created');
        $this->assertTrue($traceableSpan->isEnded());

        $traceableAttrs = $traceableSpan->getAttributes();
        $this->assertSame('cli', $traceableAttrs['transaction.type'] ?? null);
        $this->assertSame('console_command', $traceableAttrs['traceable.type'] ?? null);
        $this->assertSame('value', $traceableAttrs['command.attr'] ?? null);
    }

    public function testConsoleCommandSimpleSpan(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'test:simple']);

        $consoleSpan = $this->findSpan('console test:simple');
        $this->assertNotNull($consoleSpan, 'Console span "console test:simple" was not created');
        $this->assertTrue($consoleSpan->isEnded());

        $consoleAttrs = $consoleSpan->getAttributes();
        $this->assertSame('cli', $consoleAttrs['transaction.type'] ?? null);
        $this->assertSame('console', $consoleAttrs['console.system'] ?? null);
        $this->assertSame('test:simple', $consoleAttrs['console.command'] ?? null);

        /** @var string $commandClass */
        $commandClass = $consoleAttrs['console.command.class'] ?? '';
        $this->assertStringContainsString('SimpleCommand', $commandClass);
        $this->assertSame(0, $consoleAttrs['console.command.exit_code'] ?? null);

        $traceableSpan = $this->findSpan('traceable.console');
        $this->assertNull($traceableSpan, 'Traceable span should not be created for simple command');
    }

    public function testConsoleCommandErrorSpan(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $tester = new ApplicationTester($application);

        $exceptionThrown = false;

        try {
            $tester->run(['command' => 'test:error']);
        } catch (Throwable $e) {
            $exceptionThrown = true;
            $this->assertSame('Test error message', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Exception should be thrown when catchExceptions is false');

        $consoleSpan = $this->findSpan('console test:error');
        $this->assertNotNull($consoleSpan, 'Console span "console test:error" was not created');
        $this->assertTrue($consoleSpan->isEnded());

        $this->assertSame(StatusCode::STATUS_ERROR, $consoleSpan->getStatusCode());

        $consoleAttrs = $consoleSpan->getAttributes();
        $this->assertSame(1, $consoleAttrs['console.command.exit_code'] ?? null);
    }

    public function testConsoleCommandIgnored(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'messenger:consume']);

        $consoleSpan = $this->findSpan('console messenger:consume');
        $this->assertNull($consoleSpan, 'Console span for "messenger:consume" should be ignored');
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }
}
