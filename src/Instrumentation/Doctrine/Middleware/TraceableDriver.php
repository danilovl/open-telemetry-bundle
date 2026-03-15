<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\TracingHelper;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\{
    Driver as DriverInterface,
    DriverManager
};
use Doctrine\DBAL\Driver\{
    Connection,
    Exception
};
use Doctrine\DBAL\Platforms\{
    AbstractMySQLPlatform,
    DB2Platform,
    OraclePlatform,
    PostgreSQLPlatform,
    SQLServerPlatform,
    SQLitePlatform
};
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\{
    DoctrineMetricsInterface,
    DoctrineSpanNameHandlerInterface,
    DoctrineTraceIgnoreInterface
};
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use SensitiveParameter;

/**
 * @phpstan-import-type OverrideParams from DriverManager
 * @phpstan-import-type Params from DriverManager
 */
final class TraceableDriver extends AbstractDriverMiddleware
{
    /**
     * @param iterable<DoctrineSpanNameHandlerInterface> $doctrineSpanNameHandlers
     * @param iterable<DoctrineTraceIgnoreInterface> $doctrineTraceIgnores
     * @param DoctrineMetricsInterface|null $doctrineMetrics
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        DriverInterface $driver,
        private readonly iterable $doctrineSpanNameHandlers = [],
        private readonly iterable $doctrineTraceIgnores = [],
        private readonly ?DoctrineMetricsInterface $doctrineMetrics = null,
    ) {
        parent::__construct($driver);

        AutowireIteratorTypeValidator::validate(
            argumentName: '$doctrineSpanNameHandlers',
            items: $this->doctrineSpanNameHandlers,
            expectedType: DoctrineSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$doctrineTraceIgnores',
            items: $this->doctrineTraceIgnores,
            expectedType: DoctrineTraceIgnoreInterface::class
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $scope = Context::storage()->scope();
        $spanName = 'db.connection';
        $dbSystem = $this->resolveDbSystem($params);
        $dbName = $this->asNonEmptyString($params['dbname'] ?? null);
        $dbEndpointAttributes = $this->resolveDbEndpointAttributes($params, $dbSystem);
        $resolvedTracer = $this->instrumentation->tracer();

        $tracer = new Tracer(
            tracer: $resolvedTracer,
            doctrineSpanNameHandlers: $this->doctrineSpanNameHandlers,
            doctrineTraceIgnores: $this->doctrineTraceIgnores,
            doctrineMetrics: $this->doctrineMetrics,
            defaultAttributes: $dbEndpointAttributes,
        );

        $context = [
            DoctrineContextAttribute::OPERATION->value => 'connection.connect',
            DoctrineContextAttribute::SYSTEM->value => $dbSystem,
            DoctrineContextAttribute::NAME->value => $dbName,
            DoctrineContextAttribute::PARAMS->value => $params,
        ];

        foreach ($this->doctrineSpanNameHandlers as $doctrineSpanNameHandler) {
            $spanName = $doctrineSpanNameHandler->process($spanName, $context);
        }

        foreach ($this->doctrineTraceIgnores as $doctrineTraceIgnore) {
            if (!$doctrineTraceIgnore->shouldIgnore($spanName, $context)) {
                continue;
            }

            /** @var Params $params */
            return new TraceableConnection(
                connection: parent::connect($params),
                tracer: $tracer,
                dbSystem: $dbSystem,
                dbName: $dbName,
            );
        }

        $span = null;

        try {
            /** @var non-empty-string $spanNameNonEmpty */
            $spanNameNonEmpty = $spanName === '' ? 'db.connection' : $spanName;

            $attributes = TracingHelper::normalizeAttributeValues([
                DbAttributes::DB_NAMESPACE => $params['dbname'] ?? 'default',
                DoctrineContextAttribute::USER->value => $params['user'] ?? 'unknown',
                ...$dbEndpointAttributes,
            ]);

            $spanBuilder = $resolvedTracer
                ->spanBuilder($spanNameNonEmpty)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($scope?->context())
                ->setAttributes($attributes);

            $span = $spanBuilder->startSpan();

            /** @var Params $params */
            $connection = parent::connect($params);

            $dbSystem = $this->getSemanticDbSystem($connection->getServerVersion());
            /** @var non-empty-string $dbSystemKey */
            $dbSystemKey = DbAttributes::DB_SYSTEM_NAME;
            $span->setAttribute($dbSystemKey, $dbSystem);

            $span->setAttributes(TracingHelper::normalizeAttributeValues(
                $this->resolveDbEndpointAttributes($params, $dbSystem)
            ));

            $span->setStatus(StatusCode::STATUS_OK);

            $tracer = new Tracer(
                tracer: $resolvedTracer,
                doctrineSpanNameHandlers: $this->doctrineSpanNameHandlers,
                doctrineTraceIgnores: $this->doctrineTraceIgnores,
                doctrineMetrics: $this->doctrineMetrics,
                defaultAttributes: $this->resolveDbEndpointAttributes($params, $dbSystem),
            );

            return new TraceableConnection(
                connection: $connection,
                tracer: $tracer,
                dbSystem: $dbSystem,
                dbName: $dbName,
            );
        } catch (Exception $exception) {
            if ($span instanceof SpanInterface) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            throw $exception;
        } finally {
            if ($span instanceof SpanInterface) {
                $span->end();
            }
        }
    }

    private function getSemanticDbSystem(string $serverVersion): string
    {
        $platform = $this->getDatabasePlatform(new StaticServerVersionProvider($serverVersion));

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
            $platform instanceof DB2Platform => 'db2',
            $platform instanceof OraclePlatform => 'oracle',
            $platform instanceof PostgreSQLPlatform => DbAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
            $platform instanceof SQLitePlatform => 'sqlite',
            $platform instanceof SQLServerPlatform => 'mssql',
            default => 'other_sql',
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDbSystem(array $params): string
    {
        $driverRaw = $params['driver'] ?? '';
        $driver = mb_strtolower(is_scalar($driverRaw) ? (string) $driverRaw : '');

        if (str_contains($driver, 'mysql')) {
            return DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL;
        }

        if (str_contains($driver, 'pgsql') || str_contains($driver, 'postgres')) {
            return DbAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL;
        }

        if (str_contains($driver, 'sqlite')) {
            return 'sqlite';
        }

        if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'mssql')) {
            return 'mssql';
        }

        $urlRaw = $params['url'] ?? '';
        $url = mb_strtolower(is_scalar($urlRaw) ? (string) $urlRaw : '');

        return match (true) {
            str_starts_with($url, 'mysql://') => DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
            str_starts_with($url, 'postgres://'), str_starts_with($url, 'postgresql://') => DbAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
            str_starts_with($url, 'sqlite://') => 'sqlite',
            str_starts_with($url, 'sqlsrv://'), str_starts_with($url, 'mssql://') => 'mssql',
            default => 'other_sql',
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string|int|null>
     */
    private function resolveDbEndpointAttributes(array $params, string $dbSystem): array
    {
        $attributes = [
            'peer.service' => $dbSystem,
        ];

        $dbNamespace = $this->asNonEmptyString($params['dbname'] ?? null);
        if ($dbNamespace !== null) {
            $attributes[DbAttributes::DB_NAMESPACE] = $dbNamespace;
        }

        $dbUser = $this->asNonEmptyString($params['user'] ?? null);
        if ($dbUser !== null) {
            $attributes[DoctrineContextAttribute::USER->value] = $dbUser;
        }

        $host = $this->resolveDbHost($params);
        if ($host !== null) {
            $attributes['server.address'] = $host;
        }

        $port = $this->resolveDbPort($params);
        if ($port !== null) {
            $attributes['server.port'] = $port;
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDbHost(array $params): ?string
    {
        $host = $this->asNonEmptyString($params['host'] ?? null);
        if ($host !== null) {
            return $host;
        }

        $url = $this->asNonEmptyString($params['url'] ?? null);
        if ($url === null) {
            return null;
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            return null;
        }

        return $this->asNonEmptyString($parsedUrl['host'] ?? null);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDbPort(array $params): ?int
    {
        $port = $params['port'] ?? null;

        if (is_int($port)) {
            return $port > 0 ? $port : null;
        }

        if (is_numeric($port)) {
            $numericPort = (int) $port;

            return $numericPort > 0 ? $numericPort : null;
        }

        $url = $this->asNonEmptyString($params['url'] ?? null);
        if ($url === null) {
            return null;
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['port'])) {
            return null;
        }

        $parsedPort = $parsedUrl['port'];

        return $parsedPort > 0 ? $parsedPort : null;
    }

    /**
     * @return (non-empty-string)|null
     */
    private function asNonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmedValue = mb_trim($value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }
}
