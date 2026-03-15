<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Helper;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SqlHelper;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlHelperTest extends TestCase
{
    #[DataProvider('provideSimplifySqlCases')]
    public function testSimplifySql(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlHelper::simplifySql($sql));
    }

    #[DataProvider('provideBuildSpanNameCases')]
    public function testBuildSpanName(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlHelper::buildSpanName($sql));
    }

    public function testBuildSpanNameWithEmptyString(): void
    {
        $this->assertSame('db.sql', SqlHelper::buildSpanName('   '));
    }

    public static function provideSimplifySqlCases(): Generator
    {
        yield 'select' => ['SELECT id, name FROM users WHERE id = 1', 'select from users'];
        yield 'select with schema' => ['SELECT * FROM public.orders', 'select from public.orders'];
        yield 'insert' => ['INSERT INTO products (name) VALUES (?)', 'insert from products'];
        yield 'update' => ['UPDATE customers SET name = ? WHERE id = ?', 'update from customers'];
        yield 'delete' => ['DELETE FROM sessions WHERE expired = 1', 'delete from sessions'];
        yield 'drop' => ['DROP TABLE temp_data', 'drop from temp_data'];
        yield 'alter' => ['ALTER TABLE accounts ADD COLUMN age INT', 'alter from accounts'];
        yield 'truncate' => ['TRUNCATE TABLE logs', 'truncate from logs'];
        yield 'truncate without table keyword' => ['TRUNCATE events', 'truncate from events'];
        yield 'unknown' => ['SHOW TABLES', 'show tables'];
        yield 'whitespace normalized' => ["SELECT  *  FROM   users", 'select from users'];
    }

    public static function provideBuildSpanNameCases(): Generator
    {
        yield 'select' => ['SELECT id FROM users', 'db.users.select'];
        yield 'insert' => ['INSERT INTO orders (total) VALUES (?)', 'db.orders.insert'];
        yield 'update' => ['UPDATE products SET price = ?', 'db.products.update'];
        yield 'delete' => ['DELETE FROM sessions WHERE id = ?', 'db.sessions.delete'];
        yield 'drop' => ['DROP TABLE cache', 'db.cache.drop'];
        yield 'alter' => ['ALTER TABLE users ADD col INT', 'db.users.alter'];
        yield 'truncate' => ['TRUNCATE TABLE audit_log', 'db.audit_log.truncate'];
        yield 'unknown show' => ['SHOW TABLES', 'db.show'];
        yield 'unknown begin' => ['BEGIN', 'db.begin'];
        yield 'select with schema' => ['SELECT * FROM public.items', 'db.public.items.select'];
    }
}
