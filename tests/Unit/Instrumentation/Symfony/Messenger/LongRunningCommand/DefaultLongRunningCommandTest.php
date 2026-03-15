<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Messenger\LongRunningCommand;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\LongRunningCommand\DefaultLongRunningCommand;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultLongRunningCommandTest extends TestCase
{
    private DefaultLongRunningCommand $command;

    protected function setUp(): void
    {
        $this->command = new DefaultLongRunningCommand;
    }

    #[DataProvider('provideIsLongRunningCases')]
    public function testIsLongRunning(string $commandName, bool $expected): void
    {
        $this->assertSame($expected, $this->command->isLongRunning($commandName));
    }

    public static function provideIsLongRunningCases(): Generator
    {
        yield 'messenger:consume is long running' => ['messenger:consume', true];
        yield 'messenger:consume-messages is long running' => ['messenger:consume-messages', true];
        yield 'other command is not long running' => ['app:some-command', false];
        yield 'empty string is not long running' => ['', false];
        yield 'partial match is not long running' => ['messenger', false];
        yield 'similar but different command' => ['messenger:consume:extra', false];
    }
}
