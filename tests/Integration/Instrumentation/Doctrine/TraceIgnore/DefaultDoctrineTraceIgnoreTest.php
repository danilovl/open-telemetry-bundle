<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Integration\Instrumentation\Doctrine\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore\DefaultDoctrineTraceIgnore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\{
    ClassMetadata,
    ClassMetadataFactory
};
use Doctrine\Persistence\{
    ManagerRegistry,
    ObjectManager
};
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DefaultDoctrineTraceIgnoreTest extends TestCase
{
    private DefaultDoctrineTraceIgnore $traceIgnore;

    protected function setUp(): void
    {
        $this->traceIgnore = new DefaultDoctrineTraceIgnore;
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('provideIgnoredSpanNamesCases')]
    public function testIgnoredSpanNames(string $spanName, array $context): void
    {
        $this->assertTrue($this->traceIgnore->shouldIgnore($spanName, $context));
    }

    #[DataProvider('provideIgnoredDatabaseNameInContextCases')]
    public function testIgnoredDatabaseNameInContext(string $dbName): void
    {
        $context = [DoctrineContextAttribute::NAME->value => $dbName];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    #[DataProvider('provideIgnoredSqlInContextCases')]
    public function testIgnoredSqlInContext(string $sql): void
    {
        $context = [DoctrineContextAttribute::SQL->value => $sql];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    #[DataProvider('provideNormalSqlNotIgnoredWithoutRegistryCases')]
    public function testNormalSqlNotIgnoredWithoutRegistry(string $sql): void
    {
        $context = [DoctrineContextAttribute::SQL->value => $sql];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testEmptySqlNotIgnored(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => ''];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testNullSqlNotIgnored(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => null];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testEmptyContextNotIgnored(): void
    {
        $this->assertFalse($this->traceIgnore->shouldIgnore('db.query', []));
    }

    public function testUnknownSpanNameWithNormalSqlNotIgnored(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM products'];

        $this->assertFalse($this->traceIgnore->shouldIgnore('db.execute', $context));
    }

    public function testCaseInsensitiveIgnoredSqlMatch(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => 'select database()'];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testRegistryWithNoManagersDoesNotIgnoreNormalSql(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([]);
        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);

        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'];

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testRegistryWithKnownTableIgnoresUnknownSql(): void
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('users');
        $metadata->method('getAssociationMappings')->willReturn([]);

        $metadataFactory = $this->createStub(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$metadata]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([$em]);

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM users',
        ]));

        $this->assertTrue($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM unknown_table',
        ]));
    }

    public function testRegistryWithJoinTableMapping(): void
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('orders');
        $metadata->method('getAssociationMappings')->willReturn([
            ['joinTable' => ['name' => 'order_products']],
        ]);

        $metadataFactory = $this->createStub(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$metadata]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([$em]);

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT * FROM order_products WHERE order_id = ?',
        ]));

        $this->assertTrue($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT * FROM completely_unrelated_table',
        ]));
    }

    public function testRegistryGetManagersCalledOnlyOnce(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('getManagers')->willReturn([]);

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'];

        $traceIgnore->shouldIgnore('db.query', $context);
        $traceIgnore->shouldIgnore('db.query', $context);
    }

    public function testRegistryThrowingExceptionDoesNotIgnore(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willThrowException(new RuntimeException('connection failed'));

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'];

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testNonEntityManagerIsSkipped(): void
    {
        $objectManager = $this->createStub(ObjectManager::class);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([$objectManager]);

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'];

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', $context));
    }

    public function testMultipleManagersTablesUnion(): void
    {
        $metadataA = $this->createStub(ClassMetadata::class);
        $metadataA->method('getTableName')->willReturn('users');
        $metadataA->method('getAssociationMappings')->willReturn([]);

        $factoryA = $this->createStub(ClassMetadataFactory::class);
        $factoryA->method('getAllMetadata')->willReturn([$metadataA]);

        $emA = $this->createStub(EntityManagerInterface::class);
        $emA->method('getMetadataFactory')->willReturn($factoryA);

        $metadataB = $this->createStub(ClassMetadata::class);
        $metadataB->method('getTableName')->willReturn('products');
        $metadataB->method('getAssociationMappings')->willReturn([]);

        $factoryB = $this->createStub(ClassMetadataFactory::class);
        $factoryB->method('getAllMetadata')->willReturn([$metadataB]);

        $emB = $this->createStub(EntityManagerInterface::class);
        $emB->method('getMetadataFactory')->willReturn($factoryB);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([$emA, $emB]);

        $traceIgnore = new DefaultDoctrineTraceIgnore($registry);

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM users',
        ]));

        $this->assertFalse($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM products',
        ]));

        $this->assertTrue($traceIgnore->shouldIgnore('db.query', [
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM completely_unknown',
        ]));
    }

    public function testIgnoredSpanNameTakesPrecedenceOverSql(): void
    {
        $context = [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'];
        $this->assertTrue($this->traceIgnore->shouldIgnore('db.connection', $context));
    }

    public function testIgnoredDbNameTakesPrecedenceOverSql(): void
    {
        $context = [
            DoctrineContextAttribute::NAME->value => 'mysql',
            DoctrineContextAttribute::SQL->value => 'SELECT id FROM users',
        ];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    #[DataProvider('provideCaseInsensitiveIgnoredSqlDbNameCases')]
    public function testCaseInsensitiveIgnoredSqlDbName(string $sql): void
    {
        $context = [DoctrineContextAttribute::SQL->value => "SELECT * FROM {$sql}.tables"];

        $this->assertTrue($this->traceIgnore->shouldIgnore('db.query', $context));
    }

    public static function provideCaseInsensitiveIgnoredSqlDbNameCases(): Generator
    {
        yield 'INFORMATION_SCHEMA uppercase' => ['INFORMATION_SCHEMA'];
        yield 'Performance_Schema mixed' => ['Performance_Schema'];
        yield 'MySQL mixed' => ['MySQL'];
    }

    public static function provideIgnoredSpanNamesCases(): Generator
    {
        yield 'db.connection' => ['db.connection', []];
        yield 'db.begin' => ['db.begin', []];
        yield 'db.prepare' => ['db.prepare', []];
        yield 'db.commit' => ['db.commit', []];
        yield 'db.rollback' => ['db.rollback', []];
    }

    public static function provideIgnoredDatabaseNameInContextCases(): Generator
    {
        yield 'information_schema' => ['information_schema'];
        yield 'performance_schema' => ['performance_schema'];
        yield 'mysql' => ['mysql'];
        yield 'sys' => ['sys'];
        yield 'pg_catalog' => ['pg_catalog'];
    }

    public static function provideIgnoredSqlInContextCases(): Generator
    {
        yield 'SELECT TABLE_NAME FROM information_schema' => ['SELECT TABLE_NAME FROM information_schema.tables'];
        yield 'SELECT DATABASE()' => ['SELECT DATABASE()'];
        yield 'SELECT VERSION()' => ['SELECT VERSION()'];
        yield 'sql with information_schema substring' => ['SELECT * FROM information_schema.columns WHERE table_name = ?'];
        yield 'sql with performance_schema' => ['SELECT * FROM performance_schema.events_statements_summary_by_digest'];
        yield 'sql with mysql db' => ['SELECT * FROM mysql.user'];
        yield 'sql with pg_catalog' => ['SELECT * FROM pg_catalog.pg_tables'];
    }

    public static function provideNormalSqlNotIgnoredWithoutRegistryCases(): Generator
    {
        yield 'SELECT users' => ['SELECT id FROM users WHERE id = ?'];
        yield 'INSERT orders' => ['INSERT INTO orders (total) VALUES (?)'];
        yield 'UPDATE products' => ['UPDATE products SET price = ? WHERE id = ?'];
        yield 'DELETE sessions' => ['DELETE FROM sessions WHERE expired_at < ?'];
    }
}
