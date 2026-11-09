<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use Skir\Runtime\Exceptions\SkirRuntimeException;

/**
 * Handles int64 and hash64 values, which PHP represents as an int when they fit
 * and as a canonical decimal string otherwise.
 *
 * @internal
 */
final class LargeInteger
{
    private const string MAX_HASH64 = '18446744073709551615';

    private const float TWO_TO_THE_63 = 9_223_372_036_854_775_808.0;

    public static function normalize(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw SkirRuntimeException::invalidValue('Skir int64/hash64 values must be integers or integer strings.');
        }

        return self::parseIntegerString($value)
            ?? throw SkirRuntimeException::invalidValue('Skir int64/hash64 string values must contain only digits.');
    }

    public static function fromJson(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return self::fromFloat($value);
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (! is_string($value)) {
            throw SkirRuntimeException::invalidValue('Skir int64/hash64 JSON values must be numbers or integer strings.');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 0;
        }

        return self::parseIntegerString($trimmed)
            ?? throw SkirRuntimeException::invalidValue('Skir int64/hash64 string values must contain only digits.');
    }

    public static function fromFloat(float $value): int|string
    {
        if (! is_finite($value)) {
            throw SkirRuntimeException::invalidValue('Skir int64/hash64 values must be finite numbers.');
        }

        $rounded = Numbers::roundHalfUp($value);

        if (abs($rounded) < self::TWO_TO_THE_63) {
            return (int) $rounded;
        }

        return self::normalize(sprintf('%.0f', $rounded));
    }

    public static function clampInt64(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return str_starts_with($value, '-') ? PHP_INT_MIN : PHP_INT_MAX;
    }

    public static function clampHash64(int|string $value): int|string
    {
        if (is_int($value)) {
            return max($value, 0);
        }

        if (str_starts_with($value, '-')) {
            return 0;
        }

        if (self::compareUnsignedDecimals($value, self::MAX_HASH64) > 0) {
            return self::MAX_HASH64;
        }

        return $value;
    }

    public static function int64ToJson(mixed $value): int|string
    {
        $int64 = self::clampInt64(self::normalize($value));

        return self::isSafeInteger($int64) ? $int64 : (string) $int64;
    }

    public static function hash64ToJson(mixed $value): int|string
    {
        $hash64 = self::clampHash64(self::normalize($value));

        if (is_int($hash64)) {
            return $hash64 <= Numbers::MAX_SAFE_INTEGER ? $hash64 : (string) $hash64;
        }

        return $hash64;
    }

    /**
     * Returns the 64 bits of an unsigned hash64 value as a (possibly negative) PHP int.
     */
    public static function hash64Bits(int|string $hash64): int
    {
        if (is_int($hash64)) {
            return $hash64;
        }

        $high = 0;
        $low = 0;

        foreach (str_split($hash64) as $digit) {
            $low = $low * 10 + (int) $digit;
            $high = $high * 10 + ($low >> 32);
            $low &= 0xFFFF_FFFF;
        }

        return ($high << 32) | $low;
    }

    public static function fromUnsignedBits(int $bits): int|string
    {
        return $bits >= 0 ? $bits : sprintf('%u', $bits);
    }

    private static function isSafeInteger(int $value): bool
    {
        return abs($value) <= Numbers::MAX_SAFE_INTEGER;
    }

    private static function parseIntegerString(string $value): int|string|null
    {
        if (preg_match('/^([+-]?)0*(\d+)$/', $value, $matches) !== 1) {
            return null;
        }

        $digits = $matches[2];
        $canonical = $matches[1] === '-' && $digits !== '0' ? "-{$digits}" : $digits;
        $integer = filter_var($canonical, FILTER_VALIDATE_INT);

        return $integer === false ? $canonical : $integer;
    }

    private static function compareUnsignedDecimals(string $left, string $right): int
    {
        return [strlen($left), $left] <=> [strlen($right), $right];
    }
}
