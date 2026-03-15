<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

use Doctrine\DBAL\Driver\{
    Connection as ConnectionInterface,
    Result,
    Statement as DriverStatement
};
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SqlHelper;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SemConv\Attributes\DbAttributes;

class TraceableConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly Tracer $tracer,
        private readonly string $dbSystem = 'other_sql',
        private readonly ?string $dbName = null,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        $spanName = SqlHelper::buildSpanName($sql);
        $parts = explode('.', $spanName);
        $operation = end($parts) ?: $spanName;

        return $this->tracer->traceFunction(
            name: $spanName,
            callback: function (?SpanInterface $span) use ($sql): DriverStatement {
                if ($span instanceof SpanInterface) {
                    $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $sql);
                }

                return new TraceableStatement(
                    statement: parent::prepare($sql),
                    tracer: $this->tracer,
                    sql: $sql,
                    dbSystem: $this->dbSystem,
                    dbName: $this->dbName
                );
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => $operation,
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
                DoctrineContextAttribute::SQL->value => $sql,
            ]
        );
    }

    public function query(string $sql): Result
    {
        $spanName = SqlHelper::buildSpanName($sql);
        $parts = explode('.', $spanName);
        $operation = end($parts) ?: $spanName;

        return $this->tracer->traceFunction(
            name: $spanName,
            callback: function (?SpanInterface $span) use ($sql): Result {
                if ($span instanceof SpanInterface) {
                    $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $sql);
                }

                return parent::query($sql);
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => $operation,
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
                DoctrineContextAttribute::SQL->value => $sql,
            ]
        );
    }

    public function exec(string $sql): int
    {
        $spanName = SqlHelper::buildSpanName($sql);
        $parts = explode('.', $spanName);
        $operation = end($parts) ?: $spanName;

        return $this->tracer->traceFunction(
            name: $spanName,
            callback: function (?SpanInterface $span) use ($sql): int {
                if ($span instanceof SpanInterface) {
                    $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $sql);
                }

                return (int) parent::exec($sql);
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => $operation,
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
                DoctrineContextAttribute::SQL->value => $sql,
            ]
        );
    }

    public function beginTransaction(): void
    {
        $this->tracer->traceFunction(
            name: 'db.begin',
            callback: function (): void {
                parent::beginTransaction();
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => 'BEGIN',
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
            ]
        );
    }

    public function commit(): void
    {
        $this->tracer->traceFunction(
            name: 'db.commit',
            callback: function (): void {
                parent::commit();
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => 'COMMIT',
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
            ]
        );
    }

    public function rollBack(): void
    {
        $this->tracer->traceFunction(
            name: 'db.rollback',
            callback: function (): void {
                parent::rollBack();
            },
            context: [
                DoctrineContextAttribute::OPERATION->value => 'ROLLBACK',
                DoctrineContextAttribute::SYSTEM->value => $this->dbSystem,
                DoctrineContextAttribute::NAME->value => $this->dbName,
            ]
        );
    }
}
