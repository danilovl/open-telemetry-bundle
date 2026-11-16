<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Doctrine\Middleware;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineTraceIgnoreInterface;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Spy\SpyDoctrineMetrics;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\TracingDbalMiddleware;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler\DefaultDoctrineSpanNameHandler;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\{
    Connection as DriverConnection,
    Statement as DriverStatement
};
use Doctrine\DBAL\Platforms\MySQL80Platform;
use OpenTelemetry\API\Trace\{
    SpanKind,
    StatusCode
};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class TracingDbalMiddlewareTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private SpyDoctrineMetrics $metrics;

    private ?Throwable $queryException = null;

    protected function setUp(): void
    {
        $this->metrics = new SpyDoctrineMetrics;
    }

    /**
     * @param array<int, object> $ignores
     */
    private function connect(bool $sanitizeSql = true, array $ignores = []): DriverConnection
    {
        $statement = $this->createStub(DriverStatement::class);
        $statement->method('execute')->willReturnCallback(fn (): ArrayResult => $this->queryResult());

        $connection = $this->createStub(DriverConnection::class);
        $connection->method('getServerVersion')->willReturn('8.0.30');
        $connection->method('prepare')->willReturn($statement);
        $connection->method('query')->willReturnCallback(fn (): ArrayResult => $this->queryResult());
        $connection->method('exec')->willReturnCallback(function (): int {
            if ($this->queryException !== null) {
                throw $this->queryException;
            }

            return 3;
        });

        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);
        $driver->method('getDatabasePlatform')->willReturn(new MySQL80Platform);

        /** @var iterable<DoctrineTraceIgnoreInterface> $ignores */
        $middleware = new TracingDbalMiddleware(
            instrumentation: ScopedInstrumentation::create(TracingDbalMiddleware::INSTRUMENTATION_NAME),
            doctrineSpanNameHandlers: [new DefaultDoctrineSpanNameHandler],
            doctrineTraceIgnores: $ignores,
            doctrineMetrics: $this->metrics,
            sanitizeSql: $sanitizeSql,
        );

        return $middleware->wrap($driver)->connect([
            'driver' => 'pdo_mysql',
            'host' => 'db.internal',
            'port' => 3_306,
            'dbname' => 'shop',
            'user' => 'app',
            'password' => 's3cr3t',
        ]);
    }

    private function queryResult(): ArrayResult
    {
        if ($this->queryException !== null) {
            throw $this->queryException;
        }

        return new ArrayResult(['id'], [[1]]);
    }

    public function testConnectionSpanHidesCredentials(): void
    {
        $this->connect();

        $span = $this->telemetry->spans()[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame('mysql', $attributes['db.system.name']);
        $this->assertSame('shop', $attributes['db.namespace']);
        $this->assertSame('db.internal', $attributes['server.address']);
        $this->assertSame(3_306, $attributes['server.port']);
        $this->assertArrayNotHasKey('db.user', $attributes);
        $this->assertNotContains('s3cr3t', array_map(strval(...), array_filter($attributes, is_scalar(...))));
    }

    public function testNonPreparedQueryTextIsSanitized(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();

        $connection->query("SELECT * FROM users WHERE email = 'john@example.com' AND id = 42");

        $span = $this->telemetry->spans()[0];
        $this->assertSame('SELECT * FROM users WHERE email = ? AND id = ?', $span->getAttributes()->get('db.query.text'));
        $this->assertSame('mysql', $span->getAttributes()->get('db.system.name'));
    }

    public function testExecQueryTextIsSanitized(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();

        $this->assertSame(3, $connection->exec("UPDATE users SET password = 'hash' WHERE id = 7"));

        $this->assertSame(
            'UPDATE users SET password = ? WHERE id = ?',
            $this->telemetry->spans()[0]->getAttributes()->get('db.query.text')
        );
    }

    public function testSanitizationCanBeDisabled(): void
    {
        $connection = $this->connect(sanitizeSql: false);
        $this->telemetry->reset();

        $connection->query('SELECT 1 FROM dual WHERE id = 42');

        $this->assertSame('SELECT 1 FROM dual WHERE id = 42', $this->telemetry->spans()[0]->getAttributes()->get('db.query.text'));
    }

    public function testPreparedStatementKeepsPlaceholders(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();

        $connection->prepare('SELECT * FROM orders WHERE id = ?')->execute();

        $span = $this->telemetry->spans()[0];
        $this->assertSame('SELECT * FROM orders WHERE id = ?', $span->getAttributes()->get('db.query.text'));
        $this->assertSame('select', $span->getAttributes()->get('db.operation.name'));
    }

    public function testTransactionSpans(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();

        $connection->beginTransaction();
        $connection->commit();
        $connection->beginTransaction();
        $connection->rollBack();

        $this->assertSame(['db.begin', 'db.commit', 'db.begin', 'db.rollback'], $this->telemetry->spanNames());
    }

    public function testQuerySpanIsChildOfCurrentSpan(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();

        $parent = $this->telemetry->tracerProvider->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $parent->activate();
        $connection->query('SELECT 1');
        $scope->detach();
        $parent->end();

        $query = $this->telemetry->spans()[0];
        $this->assertSame($parent->getContext()->getSpanId(), $query->getParentSpanId());
    }

    public function testQueryErrorIsRecordedAndRethrown(): void
    {
        $connection = $this->connect();
        $this->telemetry->reset();
        $this->metrics->calls = [];
        $this->queryException = new RuntimeException('Deadlock');

        try {
            $connection->exec('DELETE FROM sessions');
            $this->fail('Exception expected');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deadlock', $exception->getMessage());
        }

        $span = $this->telemetry->spans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        $this->assertSame(['error:delete'], $this->metrics->calls);
    }

    public function testIgnoredQueriesAreNotTraced(): void
    {
        $ignore = new class() implements DoctrineTraceIgnoreInterface {
            public function shouldIgnore(string $spanName, array $context): bool
            {
                $sql = $context['db.sql'] ?? '';

                return is_string($sql) && str_contains($sql, 'messenger_messages');
            }
        };

        $connection = $this->connect(ignores: [$ignore]);
        $this->telemetry->reset();

        $connection->query('SELECT * FROM messenger_messages');
        $connection->query('SELECT * FROM users');

        $this->assertCount(1, $this->telemetry->spans());
    }
}
