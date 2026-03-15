<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Helper;

use DateTime;
use DateTimeImmutable;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\TracingHelper;
use Exception;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class TracingHelperTest extends TestCase
{
    #[DataProvider('provideNormalizeAttributeValueCases')]
    public function testNormalizeAttributeValue(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, TracingHelper::normalizeAttributeValue($value));
    }

    /**
     * @param array<string|int, mixed> $attributes
     * @param array<string|int, mixed> $expected
     */
    #[DataProvider('provideNormalizeAttributeValuesCases')]
    public function testNormalizeAttributeValues(array $attributes, array $expected): void
    {
        $this->assertSame($expected, TracingHelper::normalizeAttributeValues($attributes));
    }

    public function testNormalizeAttributeValueDateTime(): void
    {
        $date = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $result = TracingHelper::normalizeAttributeValue($date);
        $this->assertSame($date->format(DATE_ATOM), $result);
    }

    public function testNormalizeAttributeValueDateTimeMutable(): void
    {
        $date = new DateTime('2024-06-01T12:00:00+00:00');
        $result = TracingHelper::normalizeAttributeValue($date);
        $this->assertSame($date->format(DATE_ATOM), $result);
    }

    public function testExtractTracingAttributesFromObjectReturnsEmpty(): void
    {
        $result = TracingHelper::extractTracingAttributesFromObject(new stdClass);
        $this->assertSame([], $result);
    }

    public function testExtractTracingAttributesFromThrowableWithoutPrevious(): void
    {
        $result = TracingHelper::extractTracingAttributesFromObject(new Exception('test'));
        $this->assertSame([], $result);
    }

    public function testExtractTracingAttributesFromThrowableWithPrevious(): void
    {
        $previous = new RuntimeException('previous');
        $exception = new Exception('outer', 0, $previous);
        $result = TracingHelper::extractTracingAttributesFromObject($exception);
        $this->assertSame([], $result);
    }

    public static function provideNormalizeAttributeValueCases(): Generator
    {
        yield 'string' => ['hello', 'hello'];
        yield 'int' => [42, 42];
        yield 'float' => [3.14, 3.14];
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'null' => [null, null];
        yield 'array' => [['a' => 1, 'b' => 2], '{"a":1,"b":2}'];
        yield 'nested array' => [[1, 2, 3], '[1,2,3]'];
        yield 'object' => [new stdClass, stdClass::class];
    }

    public static function provideNormalizeAttributeValuesCases(): Generator
    {
        yield 'int key becomes item-N' => [
            [0 => 'zero', 1 => 'one'],
            ['item-0' => 'zero', 'item-1' => 'one']
        ];
        yield 'span type key prefixed' => [
            ['type' => 'db'],
            ['_type' => 'db']
        ];
        yield 'empty key skipped' => [
            ['' => 'value'],
            []
        ];
        yield 'empty string value skipped' => [
            ['key' => ''],
            []
        ];
        yield 'normal string values' => [
            ['service' => 'api', 'version' => '1.0'],
            ['service' => 'api', 'version' => '1.0']
        ];
        yield 'mixed types' => [
            ['count' => 5, 'active' => true],
            ['count' => 5, 'active' => true]
        ];
    }
}
