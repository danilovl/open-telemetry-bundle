<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Doctrine\SpanNameHandler;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\SpanNameHandler\DefaultDoctrineSpanNameHandler;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultDoctrineSpanNameHandlerTest extends TestCase
{
    private DefaultDoctrineSpanNameHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DefaultDoctrineSpanNameHandler;
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('provideProcessCases')]
    public function testProcess(string $spanName, array $context, string $expected): void
    {
        $this->assertSame($expected, $this->handler->process($spanName, $context));
    }

    public static function provideProcessCases(): Generator
    {
        yield 'select sql' => [
            'db.query',
            [DoctrineContextAttribute::SQL->value => 'SELECT id FROM users'],
            'db.users.select'
        ];
        yield 'insert sql' => [
            'db.query',
            [DoctrineContextAttribute::SQL->value => 'INSERT INTO orders (total) VALUES (?)'],
            'db.orders.insert'
        ];
        yield 'update sql' => [
            'db.query',
            [DoctrineContextAttribute::SQL->value => 'UPDATE products SET price = ?'],
            'db.products.update'
        ];
        yield 'delete sql' => [
            'db.query',
            [DoctrineContextAttribute::SQL->value => 'DELETE FROM sessions WHERE id = ?'],
            'db.sessions.delete'
        ];
        yield 'no sql key returns span name' => [
            'db.connection',
            [],
            'db.connection'
        ];
        yield 'empty sql returns span name' => [
            'db.prepare',
            [DoctrineContextAttribute::SQL->value => '   '],
            'db.prepare'
        ];
        yield 'null sql returns span name' => [
            'db.commit',
            [DoctrineContextAttribute::SQL->value => null],
            'db.commit'
        ];
    }
}
