<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use OpenTelemetry\API\Trace\Span;

final class SdkTestMessageHandler
{
    /** @var list<array{payload: string, traceId: string}> */
    public array $handled = [];

    public function __invoke(SdkTestMessage $message): void
    {
        $this->handled[] = ['payload' => $message->payload, 'traceId' => Span::getCurrent()->getContext()->getTraceId()];
    }
}
