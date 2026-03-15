<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineSpanNameHandlerInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SqlHelper;

final class DefaultDoctrineSpanNameHandler implements DoctrineSpanNameHandlerInterface
{
    public function process(string $spanName, array $context): string
    {
        $sql = $context[DoctrineContextAttribute::SQL->value] ?? null;

        if (!is_string($sql) || mb_trim($sql) === '') {
            return $spanName;
        }

        return SqlHelper::buildSpanName($sql);
    }
}
