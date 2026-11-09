<?php

declare(strict_types=1);

namespace Skir\Runtime;

use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Internal\BinaryReader;
use Skir\Runtime\Internal\LargeInteger;
use Skir\Runtime\Internal\Numbers;
use Skir\Runtime\Internal\TypeValues;

/**
 * The Skir binary format, byte-for-byte compatible with upstream toBytes() and fromBytes().
 */
final class BinaryFormat
{
    private const string HEADER = 'skir';

    private const string FLOAT32_NAN = "\x00\x00\xc0\x7f";

    private const string FLOAT64_NAN = "\x00\x00\x00\x00\x00\x00\xf8\x7f";

    public static function encode(Type $type, mixed $value): string
    {
        return self::HEADER.self::encodeValue($type, $value);
    }

    public static function decode(Type $type, string $bytes): mixed
    {
        if (! str_starts_with($bytes, self::HEADER)) {
            throw SkirRuntimeException::invalidBinary('Invalid skir binary header');
        }

        $reader = new BinaryReader($bytes, strlen(self::HEADER));
        $value = self::decodeValue($type, $reader);

        if ($reader->remaining() !== 0) {
            throw SkirRuntimeException::invalidBinary('Trailing or truncated binary data');
        }

        return $value;
    }

    private static function encodeValue(Type $type, mixed $value): string
    {
        return match ($type->kind) {
            TypeKind::Bool => $value ? "\x01" : "\x00",
            TypeKind::Int32 => self::encodeInt32(Numbers::toInt32($value)),
            TypeKind::Int64 => self::encodeInt64(LargeInteger::clampInt64(LargeInteger::normalize($value))),
            TypeKind::Hash64 => self::encodeHash64(LargeInteger::clampHash64(LargeInteger::normalize($value))),
            TypeKind::Float32 => self::encodeFloat((float) $value, 240, 'g', self::FLOAT32_NAN),
            TypeKind::Float64 => self::encodeFloat((float) $value, 241, 'e', self::FLOAT64_NAN),
            TypeKind::Timestamp => self::encodeTimestamp(Numbers::timestampMillis($value)),
            TypeKind::String => self::encodeString((string) $value),
            TypeKind::Bytes => self::encodeBytes((string) $value),
            TypeKind::Optional => $value === null ? "\xff" : self::encodeValue(TypeValues::itemType($type), $value),
            TypeKind::Array => self::encodeArray($type, $value),
            TypeKind::Struct => self::encodeStruct($type, TypeValues::requireStructValue($value)),
            TypeKind::Enum => self::encodeEnum($type, $value),
        };
    }

    private static function decodeValue(Type $type, BinaryReader $reader): mixed
    {
        return match ($type->kind) {
            TypeKind::Bool => self::decodeBool(self::decodeNumber($reader)),
            TypeKind::Int32 => Numbers::toInt32(self::decodeNumber($reader)),
            TypeKind::Int64, TypeKind::Hash64 => self::decodeLargeInteger(self::decodeNumber($reader)),
            TypeKind::Float32, TypeKind::Float64 => (float) Numbers::toNumber(self::decodeNumber($reader)),
            TypeKind::Timestamp => Numbers::timestampMillis(self::decodeNumber($reader)),
            TypeKind::String => self::decodeString($reader),
            TypeKind::Bytes => self::decodeBytes($reader),
            TypeKind::Optional => self::decodeOptional($type, $reader),
            TypeKind::Array => self::decodeArray($type, $reader),
            TypeKind::Struct => self::decodeStruct($type, $reader),
            TypeKind::Enum => self::decodeEnum($type, $reader),
        };
    }

    private static function encodeInt32(int $value): string
    {
        if ($value < -65_536) {
            return "\xed".pack('V', $value);
        }

        if ($value < -256) {
            return "\xec".pack('v', $value + 65_536);
        }

        if ($value < 0) {
            return "\xeb".chr($value + 256);
        }

        return self::encodeUint32($value);
    }

    private static function encodeInt64(int $value): string
    {
        if ($value < -2_147_483_648) {
            return "\xee".pack('P', $value);
        }

        if ($value > 2_147_483_647) {
            return "\xee".pack('P', $value);
        }

        return self::encodeInt32($value);
    }

    private static function encodeHash64(int|string $value): string
    {
        if (is_string($value)) {
            return "\xea".pack('P', LargeInteger::hash64Bits($value));
        }

        if ($value >= 4_294_967_296) {
            return "\xea".pack('P', $value);
        }

        return self::encodeUint32($value);
    }

    private static function encodeUint32(int $value): string
    {
        if ($value < 232) {
            return chr($value);
        }

        if ($value < 65_536) {
            return "\xe8".pack('v', $value);
        }

        if ($value < 4_294_967_296) {
            return "\xe9".pack('V', $value);
        }

        throw SkirRuntimeException::invalidValue("Skir binary lengths and variant numbers must be below 2^32, got [{$value}].");
    }

    private static function encodeFloat(float $value, int $wire, string $packFormat, string $nanBytes): string
    {
        if ($value === 0.0) {
            return "\x00";
        }

        if (is_nan($value)) {
            return chr($wire).$nanBytes;
        }

        return chr($wire).pack($packFormat, $value);
    }

    private static function encodeTimestamp(int $unixMillis): string
    {
        if ($unixMillis === 0) {
            return "\x00";
        }

        return "\xef".pack('P', $unixMillis);
    }

    /**
     * Upstream sizes the length prefix from the worst-case UTF-8 length of the
     * UTF-16 string (3 bytes per code unit), not from the actual length.
     */
    private static function encodeString(string $value): string
    {
        if ($value === '') {
            return "\xf2";
        }

        if (preg_match('//u', $value) !== 1) {
            throw SkirRuntimeException::invalidValue('Skir string values must be valid UTF-8.');
        }

        $maxEncodedLength = self::utf16Length($value) * 3;
        $length = strlen($value);

        $lengthPrefix = match (true) {
            $maxEncodedLength < 232 => chr($length),
            $maxEncodedLength < 65_536 => "\xe8".pack('v', $length),
            default => "\xe9".pack('V', $length),
        };

        return "\xf3{$lengthPrefix}{$value}";
    }

    private static function utf16Length(string $utf8): int
    {
        $continuationBytes = preg_match_all('/[\x80-\xBF]/', $utf8);
        $fourByteSequences = preg_match_all('/[\xF0-\xF7]/', $utf8);

        return strlen($utf8) - $continuationBytes + $fourByteSequences;
    }

    private static function encodeBytes(string $value): string
    {
        if ($value === '') {
            return "\xf4";
        }

        return "\xf5".self::encodeUint32(strlen($value)).$value;
    }

    private static function encodeArrayLength(int $length): string
    {
        if ($length <= 3) {
            return chr(246 + $length);
        }

        return "\xfa".self::encodeUint32($length);
    }

    private static function encodeArray(Type $type, mixed $value): string
    {
        if (! is_array($value)) {
            throw SkirRuntimeException::invalidValue('Skir array values must be PHP arrays.');
        }

        $itemType = TypeValues::itemType($type);
        $encoded = self::encodeArrayLength(count($value));

        foreach ($value as $item) {
            $encoded .= self::encodeValue($itemType, $item);
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function encodeStruct(Type $type, array $value): string
    {
        $slotCount = TypeValues::structSlotCount($type, $value);
        $encoded = self::encodeArrayLength($slotCount);

        for ($number = 0; $number < $slotCount; $number++) {
            $field = TypeValues::fieldWithKey($type, $number);

            $encoded .= $field === null
                ? "\x00"
                : self::encodeValue(TypeValues::fieldType($field), TypeValues::fieldValue($field, $value));
        }

        return $encoded;
    }

    private static function encodeEnum(Type $type, mixed $value): string
    {
        if (! $value instanceof EnumValue) {
            throw SkirRuntimeException::invalidValue('Skir enum values must be EnumValue instances.');
        }

        if ($value->name === TypeValues::UNKNOWN_VARIANT) {
            return "\x00";
        }

        $variant = TypeValues::variantWithName($type, $value->name);

        if (! $value->wrapper) {
            return self::encodeUint32($variant->number);
        }

        if (! $variant->isWrapper()) {
            throw SkirRuntimeException::invalidValue("Skir enum variant [{$value->name}] does not carry a payload.");
        }

        $header = $variant->number < 5
            ? chr(250 + $variant->number)
            : "\xf8".self::encodeUint32($variant->number);

        return $header.self::encodeValue($variant->payloadType, $value->value);
    }

    /**
     * Returns an int, a float, or a decimal string for unsigned 64-bit values above PHP_INT_MAX.
     */
    private static function decodeNumber(BinaryReader $reader): int|float|string
    {
        $wire = $reader->readUint8();

        return match (true) {
            $wire < 232 => $wire,
            $wire === 232 => $reader->readFormatted('v', 2),
            $wire === 233 => $reader->readFormatted('V', 4),
            $wire === 234 => LargeInteger::fromUnsignedBits($reader->readFormatted('P', 8)),
            $wire === 235 => $reader->readUint8() - 256,
            $wire === 236 => $reader->readFormatted('v', 2) - 65_536,
            $wire === 237 => Numbers::toInt32($reader->readFormatted('V', 4)),
            $wire === 238, $wire === 239 => $reader->readFormatted('P', 8),
            $wire === 240 => $reader->readFormatted('g', 4),
            $wire === 241 => $reader->readFormatted('e', 8),
            default => throw SkirRuntimeException::invalidBinary("Invalid numeric wire tag: {$wire}"),
        };
    }

    private static function decodeUint32(BinaryReader $reader): int
    {
        $wire = $reader->peekUint8();

        if ($wire > 233) {
            throw SkirRuntimeException::invalidBinary("Invalid unsigned length or variant tag: {$wire}");
        }

        return self::decodeNumber($reader);
    }

    private static function decodeArrayLength(int $wire, BinaryReader $reader): int
    {
        if ($wire < 246) {
            throw SkirRuntimeException::invalidBinary("Invalid array wire tag: {$wire}");
        }

        if ($wire > 250) {
            throw SkirRuntimeException::invalidBinary("Invalid array wire tag: {$wire}");
        }

        $length = $wire === 250 ? self::decodeUint32($reader) : $wire - 246;

        if ($length > $reader->remaining()) {
            throw SkirRuntimeException::invalidBinary('Truncated binary array');
        }

        return $length;
    }

    private static function decodeBool(int|float|string $number): bool
    {
        if (is_float($number)) {
            return ! is_nan($number) && $number !== 0.0;
        }

        return $number !== 0;
    }

    private static function decodeLargeInteger(int|float|string $number): int|string
    {
        if (is_float($number)) {
            return LargeInteger::fromFloat($number);
        }

        return $number;
    }

    private static function decodeString(BinaryReader $reader): string
    {
        $wire = $reader->readUint8();

        if ($wire === 0) {
            return '';
        }

        if ($wire === 242) {
            return '';
        }

        if ($wire !== 243) {
            throw SkirRuntimeException::invalidBinary("Invalid string wire tag: {$wire}");
        }

        $value = $reader->read(self::decodeUint32($reader));

        if (preg_match('//u', $value) !== 1) {
            throw SkirRuntimeException::invalidBinary('Skir string values must be valid UTF-8.');
        }

        return $value;
    }

    private static function decodeBytes(BinaryReader $reader): string
    {
        $wire = $reader->readUint8();

        if ($wire === 0) {
            return '';
        }

        if ($wire === 244) {
            return '';
        }

        if ($wire !== 245) {
            throw SkirRuntimeException::invalidBinary("Invalid bytes wire tag: {$wire}");
        }

        return $reader->read(self::decodeUint32($reader));
    }

    private static function decodeOptional(Type $type, BinaryReader $reader): mixed
    {
        if ($reader->peekUint8() === 255) {
            $reader->readUint8();

            return null;
        }

        return self::decodeValue(TypeValues::itemType($type), $reader);
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeArray(Type $type, BinaryReader $reader): array
    {
        $wire = $reader->readUint8();

        if ($wire === 0) {
            return [];
        }

        $length = self::decodeArrayLength($wire, $reader);
        $itemType = TypeValues::itemType($type);
        $items = [];

        for ($index = 0; $index < $length; $index++) {
            $items[] = self::decodeValue($itemType, $reader);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStruct(Type $type, BinaryReader $reader): array
    {
        $decoded = TypeValues::defaultValue($type);
        $wire = $reader->readUint8();

        if ($wire === 0) {
            return $decoded;
        }

        $slotCount = self::decodeArrayLength($wire, $reader);

        for ($number = 0; $number < $slotCount; $number++) {
            $field = TypeValues::fieldWithKey($type, $number);

            if ($field === null) {
                self::skipValue($reader);

                continue;
            }

            $decoded[$field->name] = self::decodeValue(TypeValues::fieldType($field), $reader);
        }

        return $decoded;
    }

    private static function decodeEnum(Type $type, BinaryReader $reader): EnumValue
    {
        $wire = $reader->peekUint8();

        if ($wire < 242) {
            return self::decodeEnumConstant($type, self::decodeUint32($reader));
        }

        $reader->readUint8();

        if ($wire === 248) {
            return self::decodeEnumWrapper($type, self::decodeUint32($reader), $reader);
        }

        if ($wire < 251) {
            throw SkirRuntimeException::invalidBinary("Invalid enum wire tag: {$wire}");
        }

        if ($wire > 254) {
            throw SkirRuntimeException::invalidBinary("Invalid enum wire tag: {$wire}");
        }

        return self::decodeEnumWrapper($type, $wire - 250, $reader);
    }

    private static function decodeEnumConstant(Type $type, int $number): EnumValue
    {
        $variant = TypeValues::variantWithNumber($type, $number);

        if ($variant === null) {
            return EnumValue::constant(TypeValues::UNKNOWN_VARIANT);
        }

        if ($variant->isWrapper()) {
            return EnumValue::wrapper($variant->name, TypeValues::defaultValue($variant->payloadType));
        }

        return EnumValue::constant($variant->name);
    }

    private static function decodeEnumWrapper(Type $type, int $number, BinaryReader $reader): EnumValue
    {
        $variant = TypeValues::variantWithNumber($type, $number);

        if ($variant === null) {
            self::skipValue($reader);

            return EnumValue::constant(TypeValues::UNKNOWN_VARIANT);
        }

        if (! $variant->isWrapper()) {
            self::skipValue($reader);

            return EnumValue::constant($variant->name);
        }

        return EnumValue::wrapper($variant->name, self::decodeValue($variant->payloadType, $reader));
    }

    private static function skipValue(BinaryReader $reader): void
    {
        $wire = $reader->readUint8();

        match ($wire) {
            232, 236 => $reader->read(2),
            233, 237, 240 => $reader->read(4),
            234, 238, 239, 241 => $reader->read(8),
            235 => $reader->read(1),
            243, 245 => $reader->read(self::decodeUint32($reader)),
            247, 251, 252, 253, 254 => self::skipValues($reader, 1),
            248 => self::skipValues($reader, 2),
            249 => self::skipValues($reader, 3),
            250 => self::skipValues($reader, self::decodeArrayLength($wire, $reader)),
            default => null,
        };
    }

    private static function skipValues(BinaryReader $reader, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            self::skipValue($reader);
        }
    }
}
