<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Mailer\Interfaces;

use Throwable;

interface MailerMetricsInterface
{
    public function recordSent(object $message, string $transport, float $durationMs): void;

    public function recordFailed(object $message, string $transport, Throwable $error, float $durationMs): void;
}
