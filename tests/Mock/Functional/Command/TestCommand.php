<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Command;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test:traceable')]
#[Traceable(name: 'console.test_command', attributes: ['command.attr' => 'value'], handler: TraceableHandler::COMMAND)]
class TestCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Test command executed');

        return Command::SUCCESS;
    }
}
