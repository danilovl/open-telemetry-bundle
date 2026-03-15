<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\HttpKernel\Interfaces;

use Symfony\Component\HttpFoundation\Request;
use Throwable;

interface HttpServerMetricsInterface
{
    public function recordRequest(Request $request, int $statusCode, float $durationMs): void;

    public function recordError(Request $request, Throwable $exception): void;
}
