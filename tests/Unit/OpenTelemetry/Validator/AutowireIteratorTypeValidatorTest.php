<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Validator;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use DateTime;

class AutowireIteratorTypeValidatorTest extends TestCase
{
    /**
     * @param array<mixed> $items
     * @param class-string<object> $type
     */
    #[DataProvider('provideValidateReturnsValidatedArrayCases')]
    public function testValidateReturnsValidatedArray(string $argName, array $items, string $type, int $expectedCount): void
    {
        $result = AutowireIteratorTypeValidator::validate($argName, $items, $type);
        $this->assertCount($expectedCount, $result);
    }

    public function testValidateKeepsStringKeys(): void
    {
        $obj = new stdClass;
        $result = AutowireIteratorTypeValidator::validate('arg', ['myKey' => $obj], stdClass::class);
        $this->assertArrayHasKey('myKey', $result);
        $this->assertSame($obj, $result['myKey']);
    }

    public function testValidateKeepsIntKeys(): void
    {
        $obj = new stdClass;
        $result = AutowireIteratorTypeValidator::validate('arg', [0 => $obj], stdClass::class);
        $this->assertArrayHasKey(0, $result);
        $this->assertSame($obj, $result[0]);
    }

    public function testValidateThrowsOnInvalidItem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected instance of/');

        AutowireIteratorTypeValidator::validate('myArg', [new stdClass], DateTime::class);
    }

    public function testValidateExceptionMessageContainsArgumentName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"myCustomArg"/');

        AutowireIteratorTypeValidator::validate('myCustomArg', [new stdClass], DateTime::class);
    }

    public function testValidateExceptionMessageContainsExpectedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(DateTime::class, '/') . '/');

        AutowireIteratorTypeValidator::validate('arg', [new stdClass], DateTime::class);
    }

    public function testValidateWithGeneratorInput(): void
    {
        $generator = (static function (): Generator {
            yield 'first' => new stdClass;
            yield 'second' => new stdClass;
        })();

        $result = AutowireIteratorTypeValidator::validate('arg', $generator, stdClass::class);

        $this->assertCount(2, $result);
    }

    public static function provideValidateReturnsValidatedArrayCases(): Generator
    {
        yield 'array of stdClass' => [
            'items',
            [new stdClass, new stdClass],
            stdClass::class,
            2
        ];
        yield 'single item' => [
            'arg',
            ['key' => new stdClass],
            stdClass::class,
            1
        ];
        yield 'empty iterable' => [
            'arg',
            [],
            stdClass::class,
            0
        ];
    }
}
