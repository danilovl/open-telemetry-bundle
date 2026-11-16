<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

final readonly class SdkTestMessage
{
    public function __construct(public string $payload = 'payload') {}
}
