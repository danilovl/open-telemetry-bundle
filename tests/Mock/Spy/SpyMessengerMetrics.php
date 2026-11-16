<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Spy;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces\MessengerMetricsInterface;
use Throwable;

final class SpyMessengerMetrics implements MessengerMetricsInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function recordMessage(
        object $message,
        string $operation,
        array $messagingAttributes,
        float $durationMs,
    ): void {
        $this->calls[] = 'message:' . $operation;
    }

    public function recordError(
        object $message,
        string $operation,
        array $messagingAttributes,
        Throwable $exception,
        float $durationMs,
    ): void {
        $this->calls[] = 'error:' . $operation;
    }
}
