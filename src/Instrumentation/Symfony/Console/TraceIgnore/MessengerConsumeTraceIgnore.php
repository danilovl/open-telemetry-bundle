<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Console\Interfaces\ConsoleTraceIgnoreInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

final class MessengerConsumeTraceIgnore implements ConsoleTraceIgnoreInterface
{
    /**
     * @var list<string>
     */
    private const array IGNORED_COMMANDS = [
        'messenger:consume',
        'messenger:consume-messages',
    ];

    public function shouldIgnore(string $spanName, ConsoleCommandEvent $event): bool
    {
        $commandName = $event->getCommand()?->getName();

        if ($commandName === null) {
            return false;
        }

        return in_array($commandName, self::IGNORED_COMMANDS, true);
    }
}
