<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Symfony\Console\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\TraceIgnore\MessengerConsumeTraceIgnore;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class MessengerConsumeTraceIgnoreTest extends TestCase
{
    private MessengerConsumeTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new MessengerConsumeTraceIgnore;
    }

    private function makeEvent(?string $commandName): ConsoleCommandEvent
    {
        $command = null;

        if ($commandName !== null) {
            $command = $this->createStub(Command::class);
            $command->method('getName')->willReturn($commandName);
        }

        return new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput);
    }

    #[DataProvider('provideIgnoredCommandsCases')]
    public function testIgnoredCommands(string $commandName): void
    {
        $result = $this->traceIgnore->shouldIgnore('span', $this->makeEvent($commandName));

        $this->assertTrue($result);
    }

    #[DataProvider('provideNotIgnoredCommandsCases')]
    public function testNotIgnoredCommands(string $commandName): void
    {
        $result = $this->traceIgnore->shouldIgnore('span', $this->makeEvent($commandName));

        $this->assertFalse($result);
    }

    public function testNullCommandNotIgnored(): void
    {
        $result = $this->traceIgnore->shouldIgnore('span', $this->makeEvent(null));

        $this->assertFalse($result);
    }

    public function testSpanNameIsNotUsed(): void
    {
        $event = $this->makeEvent('messenger:consume');

        $this->assertTrue($this->traceIgnore->shouldIgnore('any.span', $event));
        $this->assertTrue($this->traceIgnore->shouldIgnore('', $event));
    }

    public static function provideIgnoredCommandsCases(): Generator
    {
        yield 'messenger:consume' => ['messenger:consume'];
        yield 'messenger:consume-messages' => ['messenger:consume-messages'];
    }

    public static function provideNotIgnoredCommandsCases(): Generator
    {
        yield 'app:my-command' => ['app:my-command'];
        yield 'cache:clear' => ['cache:clear'];
        yield 'messenger:stop-workers' => ['messenger:stop-workers'];
        yield 'messenger:consume:extra' => ['messenger:consume:extra'];
        yield 'MESSENGER:CONSUME uppercase' => ['MESSENGER:CONSUME'];
    }
}
