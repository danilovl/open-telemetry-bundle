<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class RedisHookSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<AbstractRedisHookInstrumentation> $instrumentations
     */
    public function __construct(private readonly iterable $instrumentations = []) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['register', 4_096],
            ConsoleEvents::COMMAND => ['register', 4_096],
        ];
    }

    public function register(): void
    {
        foreach ($this->instrumentations as $instrumentation) {
            $instrumentation->register();
        }
    }
}
