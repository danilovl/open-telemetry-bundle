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

    #[DataProvider('provideSanitizeCases')]
    public function testSanitize(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlHelper::sanitize($sql));
    }

    public function testDoubleQuotedStringsMysql(): void
    {
        $this->assertSame(
            'SELECT * FROM `users` WHERE email = ? AND name = ? AND token = ?',
            SqlHelper::sanitize('SELECT * FROM `users` WHERE email = "john@example.com" AND name = "say ""hi""" AND token = \'secret\'', true)
        );
    }

    public function testDoubleQuotedIdentifiersKept(): void
    {
        $this->assertSame(
            'SELECT "id" FROM "users" WHERE "email" = ?',
            SqlHelper::sanitize('SELECT "id" FROM "users" WHERE "email" = \'john@example.com\'')
        );
    }

    public static function provideSanitizeCases(): Generator
    {
        yield 'string literal' => ["SELECT * FROM users WHERE email = 'john@example.com'", 'SELECT * FROM users WHERE email = ?'];
        yield 'escaped quotes' => ["SELECT 1 FROM t WHERE n = 'O''Brien' OR m = 'a\\'b'", 'SELECT ? FROM t WHERE n = ? OR m = ?'];
        yield 'numbers' => ['SELECT * FROM t WHERE id = 42 AND price > 9.99 AND x = -1 AND e = 1e10', 'SELECT * FROM t WHERE id = ? AND price > ? AND x = ? AND e = ?'];
        yield 'in list' => ['DELETE FROM t WHERE id IN (1, 2, 3)', 'DELETE FROM t WHERE id IN (?, ?, ?)'];
        yield 'identifiers with digits untouched' => ['SELECT t1.col2 FROM table3 t1', 'SELECT t1.col2 FROM table3 t1'];
        yield 'placeholders untouched' => ['SELECT * FROM t WHERE a = ? AND b = :p1 AND c = $1 AND d = ?2', 'SELECT * FROM t WHERE a = ? AND b = :p1 AND c = $1 AND d = ?2'];
        yield 'hex literal' => ['INSERT INTO t VALUES (0xDEADBEEF)', 'INSERT INTO t VALUES (?)'];
        yield 'postgres dollar quoted' => ['SELECT $$secret text$$, $tag$more$tag$', 'SELECT ?, ?'];
        yield 'national string' => ["SELECT N'unicode'", 'SELECT ?'];
        yield 'multiline string' => ["UPDATE t SET body = 'line1\nline2' WHERE id = 5", 'UPDATE t SET body = ? WHERE id = ?'];
        yield 'quoted identifiers kept' => ['SELECT "col1", `col2` FROM "Table9"', 'SELECT "col1", `col2` FROM "Table9"'];
        yield 'no literals' => ['SELECT * FROM users', 'SELECT * FROM users'];
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
