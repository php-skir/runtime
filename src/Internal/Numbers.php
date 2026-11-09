<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use DateTimeInterface;
use Skir\Runtime\Exceptions\SkirRuntimeException;

/**
 * Mirrors the JavaScript number coercions the upstream Skir runtime relies on.
 *
 * @internal
 */
final class Numbers
{
    public const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const int MAX_TIMESTAMP_MILLIS = 8_640_000_000_000_000;

    private const float TWO_TO_THE_32 = 4_294_967_296.0;

    public static function toNumber(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if ($value === null) {
            return 0;
        }

        if (! is_string($value)) {
            return NAN;
        }

        return self::stringToNumber(trim($value));
    }

    public static function toInt32(mixed $value): int
    {
        $number = self::toNumber($value);

        if (is_float($number)) {
            $number = self::floatToInt32Range($number);
        }

        $low32Bits = $number & 0xFFFF_FFFF;

        return $low32Bits >= 0x8000_0000 ? $low32Bits - 0x1_0000_0000 : $low32Bits;
    }

    public static function isZero(mixed $value): bool
    {
        return in_array(self::toNumber($value), [0, 0.0], true);
    }

    public static function roundHalfUp(float $value): float
    {
        $floor = floor($value);

        return $value - $floor >= 0.5 ? $floor + 1 : $floor;
    }

    public static function timestampMillis(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return self::clampTimestampMillis(((int) $value->format('U')) * 1000 + ((int) $value->format('v')));
        }

        return self::clampTimestampMillis(self::toNumber($value));
    }

    private static function clampTimestampMillis(int|float $millis): int
    {
        if (is_nan($millis)) {
            throw SkirRuntimeException::invalidValue('Skir timestamp values must be numbers of milliseconds.');
        }

        if ($millis <= -self::MAX_TIMESTAMP_MILLIS) {
            return -self::MAX_TIMESTAMP_MILLIS;
        }

        if ($millis >= self::MAX_TIMESTAMP_MILLIS) {
            return self::MAX_TIMESTAMP_MILLIS;
        }

        return is_float($millis) ? (int) self::roundHalfUp($millis) : $millis;
    }

    private static function stringToNumber(string $value): int|float
    {
        if ($value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        return match ($value) {
            'Infinity', '+Infinity' => INF,
            '-Infinity' => -INF,
            default => NAN,
        };
    }

    private static function floatToInt32Range(float $value): int
    {
        if (! is_finite($value)) {
            return 0;
        }

        $truncated = $value < 0 ? ceil($value) : floor($value);

        return (int) fmod($truncated, self::TWO_TO_THE_32);
    }
}
