<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Messenger\Interfaces;

use Throwable;

interface MessengerMetricsInterface
{
    /**
     * @param array<string, mixed> $messagingAttributes
     */
    public function recordMessage(object $message, string $operation, array $messagingAttributes, float $durationMs): void;

    /**
     * @param array<string, mixed> $messagingAttributes
     */
    public function recordError(object $message, string $operation, array $messagingAttributes, Throwable $exception, float $durationMs): void;
}
