<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Redis;

use LogicException;
use Predis\ClientInterface;
use Predis\Command\{
    CommandInterface,
    FactoryInterface
};
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;

class MockPredisClient implements ClientInterface
{
    public function get(mixed $key): mixed
    {
        return null;
    }

    public function set(mixed $key, mixed $value, mixed $expireResolution = null, mixed $expireTTL = null, mixed $flag = null): mixed
    {
        return null;
    }

    public function setex(mixed $key, mixed $seconds, mixed $value): mixed
    {
        return null;
    }

    public function del(mixed $keyOrKeys, mixed ...$keys): mixed
    {
        return null;
    }

    public function expire(mixed $key, mixed $seconds): mixed
    {
        return null;
    }

    public function getCommandFactory(): FactoryInterface
    {
        throw new LogicException;
    }

    public function getOptions(): OptionsInterface
    {
        throw new LogicException;
    }

    public function connect(): void {}

    public function disconnect(): void {}

    public function getConnection(): ConnectionInterface
    {
        throw new LogicException;
    }

    /** @param array<int, mixed> $arguments */
    public function createCommand($method, $arguments = []): CommandInterface
    {
        throw new LogicException;
    }

    public function executeCommand(CommandInterface $command): mixed
    {
        return null;
    }

    /** @param array<int, mixed> $arguments */
    public function __call(mixed $method, mixed $arguments): mixed
    {
        return null;
    }
}
