<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use JsonException;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use stdClass;

/**
 * Produces the same text as JavaScript's JSON.stringify(value, undefined, indent).
 *
 * @internal
 */
final class JsonWriter
{
    private const int STRING_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * @throws JsonException
     */
    public static function write(mixed $value, string $indent = ''): string
    {
        return self::writeValue($value, $indent, '');
    }

    /**
     * @throws JsonException
     */
    private static function writeValue(mixed $value, string $indent, string $indentation): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::writeFloat($value),
            is_string($value) => json_encode($value, self::STRING_FLAGS),
            $value instanceof stdClass => self::writeObject((array) $value, $indent, $indentation),
            is_array($value) => array_is_list($value)
                ? self::writeList($value, $indent, $indentation)
                : self::writeObject($value, $indent, $indentation),
            default => throw SkirRuntimeException::invalidValue('Only JSON-compatible values can be written as JSON.'),
        };
    }

    /**
     * @param  array<int, mixed>  $items
     *
     * @throws JsonException
     */
    private static function writeList(array $items, string $indent, string $indentation): string
    {
        $innerIndentation = "{$indentation}{$indent}";
        $entries = array_map(
            fn (mixed $item): string => self::writeValue($item, $indent, $innerIndentation),
            $items,
        );

        return self::writeContainer('[', $entries, ']', $indent, $indentation);
    }

    /**
     * @param  array<int|string, mixed>  $properties
     *
     * @throws JsonException
     */
    private static function writeObject(array $properties, string $indent, string $indentation): string
    {
        $innerIndentation = "{$indentation}{$indent}";
        $separator = $indent === '' ? ':' : ': ';
        $entries = [];

        foreach ($properties as $name => $value) {
            $entries[] = json_encode((string) $name, self::STRING_FLAGS)
                .$separator
                .self::writeValue($value, $indent, $innerIndentation);
        }

        return self::writeContainer('{', $entries, '}', $indent, $indentation);
    }

    /**
     * @param  array<int, string>  $entries
     */
    private static function writeContainer(string $open, array $entries, string $close, string $indent, string $indentation): string
    {
        if ($entries === []) {
            return "{$open}{$close}";
        }

        if ($indent === '') {
            return $open.implode(',', $entries).$close;
        }

        $innerIndentation = "{$indentation}{$indent}";

        return "{$open}\n{$innerIndentation}".implode(",\n{$innerIndentation}", $entries)."\n{$indentation}{$close}";
    }

    /**
     * Formats a float like ECMAScript's Number::toString.
     */
    private static function writeFloat(float $value): string
    {
        if (! is_finite($value)) {
            return 'null';
        }

        if ($value === 0.0) {
            return '0';
        }

        [$digits, $decimalPointPosition] = self::shortestDigits(abs($value));
        $sign = $value < 0 ? '-' : '';
        $digitCount = strlen($digits);

        if ($decimalPointPosition <= 21) {
            if ($digitCount <= $decimalPointPosition) {
                return $sign.$digits.str_repeat('0', $decimalPointPosition - $digitCount);
            }

            if ($decimalPointPosition > 0) {
                return $sign.substr($digits, 0, $decimalPointPosition).'.'.substr($digits, $decimalPointPosition);
            }
        }

        if ($decimalPointPosition > -6) {
            if ($decimalPointPosition <= 0) {
                return "{$sign}0.".str_repeat('0', -$decimalPointPosition).$digits;
            }
        }

        $exponent = $decimalPointPosition - 1;
        $exponentSign = $exponent < 0 ? '-' : '+';
        $mantissa = $digitCount === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return "{$sign}{$mantissa}e{$exponentSign}".abs($exponent);
    }

    /**
     * Returns the shortest digit string that round-trips to the given positive float,
     * and the position of the decimal point relative to those digits.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $value): array
    {
        for ($precision = 0; $precision < 17; $precision++) {
            $scientific = sprintf("%.{$precision}e", $value);

            if ((float) $scientific === $value) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $scientific);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$digits, (int) $exponent + 1];
    }
}
