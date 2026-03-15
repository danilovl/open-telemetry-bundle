<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\{
    Result,
    Statement as StatementInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SqlHelper;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SemConv\Attributes\DbAttributes;

final class TraceableStatement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly Tracer $tracer,
        private readonly ?string $sql = null,
        private readonly string $dbSystem = 'other_sql',
        private readonly ?string $dbName = null,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $spanName = $this->sql !== null ? SqlHelper::buildSpanName($this->sql) : 'db.execute';
        $parts = explode('.', $spanName);
        $operation = end($parts) ?: $spanName;

        return $this->tracer->traceFunction(
            name: $spanName,
            callback: function (?SpanInterface $span): Result {
                if ($span instanceof SpanInterface && null !== $this->sql) {
                    $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $this->sql);
                }

                return parent::execute();
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => $operation,
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
                DoctrineContextAttribute::SQL->value => $this->sql,
            ]
        );
    }
}
