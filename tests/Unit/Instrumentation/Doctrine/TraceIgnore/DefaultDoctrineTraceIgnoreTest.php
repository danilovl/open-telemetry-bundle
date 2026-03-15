<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Doctrine\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore\DefaultDoctrineTraceIgnore;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultDoctrineTraceIgnoreTest extends TestCase
{
    private DefaultDoctrineTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new DefaultDoctrineTraceIgnore(null);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('provideShouldIgnoreIgnoredSpanNamesCases')]
    public function testShouldIgnoreIgnoredSpanNames(string $spanName, array $context): void
    {
        $this->assertTrue($this->traceIgnore->shouldIgnore($spanName, $context));
    }

    #[DataProvider('provideShouldIgnoreIgnoredDatabaseNameCases')]
    public function testShouldIgnoreIgnoredDatabaseName(string $dbName): void
    {
        $context = [DoctrineContextAttribute::NAME->value => $dbName];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    #[DataProvider('provideShouldIgnoreIgnoredSqlQueriesCases')]
    public function testShouldIgnoreIgnoredSqlQueries(string $sql): void
    {
        $context = [DoctrineContextAttribute::SQL->value => $sql];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testShouldNotIgnoreNormalQuery(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM app_users'];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testShouldNotIgnoreEmptyContext(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', []));
    }

    public function testShouldNotIgnoreEmptySql(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => ''];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testShouldNotIgnoreUnknownDbName(): void
    {
        $context = [DoctrineContextAttribute::NAME->value => 'my_app_db'];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public static function provideShouldIgnoreIgnoredSpanNamesCases(): Generator
    {
        yield 'db.connection' => ['db.connection', []];
        yield 'db.begin' => ['db.begin', []];
        yield 'db.prepare' => ['db.prepare', []];
        yield 'db.commit' => ['db.commit', []];
        yield 'db.rollback' => ['db.rollback', []];
    }

    public static function provideShouldIgnoreIgnoredDatabaseNameCases(): Generator
    {
        yield 'information_schema' => ['information_schema'];
        yield 'performance_schema' => ['performance_schema'];
        yield 'mysql' => ['mysql'];
        yield 'sys' => ['sys'];
        yield 'pg_catalog' => ['pg_catalog'];
    }

    public static function provideShouldIgnoreIgnoredSqlQueriesCases(): Generator
    {
        yield 'information_schema query' => ['SELECT TABLE_NAME FROM information_schema.TABLES'];
        yield 'SELECT DATABASE()' => ['SELECT DATABASE()'];
        yield 'SELECT VERSION()' => ['SELECT VERSION()'];
        yield 'sql containing information_schema' => ['SELECT * FROM information_schema.columns'];
        yield 'sql containing performance_schema' => ['SELECT * FROM performance_schema.events'];
        yield 'sql containing pg_catalog' => ['SELECT * FROM pg_catalog.tables'];
    }
}
