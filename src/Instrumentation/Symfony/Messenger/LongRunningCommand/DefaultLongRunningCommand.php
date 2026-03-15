<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\LongRunningCommand;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\LongRunningCommandInterface;

final class DefaultLongRunningCommand implements LongRunningCommandInterface
{
    /**
     * @var list<string>
     */
    private const array LONG_RUNNING_COMMAND = [
        'messenger:consume',
        'messenger:consume-messages',
    ];

    public function isLongRunning(string $commandName): bool
    {
        return in_array($commandName, self::LONG_RUNNING_COMMAND, true);
    }
}
