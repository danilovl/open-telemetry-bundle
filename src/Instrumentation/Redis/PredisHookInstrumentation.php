<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis;

/** executeCommand() is called for every command, including magic methods. */
final class PredisHookInstrumentation extends AbstractRedisHookInstrumentation
{
    protected function resolveCommand(array $params, string $function): array
    {
        $command = $params[0] ?? null;

        if (!is_object($command) || !method_exists($command, 'getId')) {
            return ['UNKNOWN', ''];
        }

        $id = mb_strtoupper((string) $command->getId());
        $arguments = method_exists($command, 'getArguments') ? (array) $command->getArguments() : [];

        return [$id === '' ? 'UNKNOWN' : $id, self::extractKey($id, $arguments[0] ?? null)];
    }

    protected function getHookTargets(): array
    {
        return [['Predis\\ClientInterface', 'executeCommand']];
    }

    protected function getClientName(): string
    {
        return 'predis';
    }
}
