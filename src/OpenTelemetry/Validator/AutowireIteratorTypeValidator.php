<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator;

use InvalidArgumentException;

readonly class AutowireIteratorTypeValidator
{
    /**
     * @template T of object
     *
     * @param iterable<mixed> $items
     * @param class-string<T> $expectedType
     *
     * @return array<int|string, T>
     */
    public static function validate(string $argumentName, iterable $items, string $expectedType): array
    {
        $validated = [];

        foreach ($items as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                continue;
            }

            if (!$item instanceof $expectedType) {
                $message = sprintf(
                    'Invalid service for argument "%s": expected instance of "%s", got "%s" at key "%s".',
                    $argumentName,
                    $expectedType,
                    get_debug_type($item),
                    $key
                );

                throw new InvalidArgumentException($message);
            }

            $validated[$key] = $item;
        }

        return $validated;
    }
}
