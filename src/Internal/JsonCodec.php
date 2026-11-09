<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use DateTimeImmutable;
use Skir\Runtime\EnumValue;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Type;
use Skir\Runtime\TypeKind;
use stdClass;

/**
 * Converts between PHP values and Skir JSON values. Encoding targets one flavor,
 * decoding accepts both flavors, like upstream's toJson(input, flavor) and fromJson(json).
 *
 * @internal
 */
final class JsonCodec
{
    public static function encode(Type $type, mixed $value, JsonFlavor $flavor): mixed
    {
        return match ($type->kind) {
            TypeKind::Bool => self::encodeBool($value, $flavor),
            TypeKind::Int32 => Numbers::toInt32($value),
            TypeKind::Int64 => LargeInteger::int64ToJson($value),
            TypeKind::Hash64 => LargeInteger::hash64ToJson($value),
            TypeKind::Float32, TypeKind::Float64 => self::encodeFloat((float) $value),
            TypeKind::Timestamp => self::encodeTimestamp(Numbers::timestampMillis($value), $flavor),
            TypeKind::String => (string) $value,
            TypeKind::Bytes => self::encodeBytes((string) $value, $flavor),
            TypeKind::Optional => $value === null ? null : self::encode(TypeValues::itemType($type), $value, $flavor),
            TypeKind::Array => self::encodeArray($type, $value, $flavor),
            TypeKind::Struct => $flavor === JsonFlavor::Readable
                ? self::encodeReadableStruct($type, TypeValues::requireStructValue($value))
                : self::encodeDenseStruct($type, TypeValues::requireStructValue($value)),
            TypeKind::Enum => self::encodeEnum($type, $value, $flavor),
        };
    }

    public static function decode(Type $type, mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (in_array($value, [0, 0.0], true)) {
            return TypeValues::defaultValue($type->kind === TypeKind::Optional ? TypeValues::itemType($type) : $type);
        }

        return match ($type->kind) {
            TypeKind::Bool => self::decodeBool($value),
            TypeKind::Int32 => Numbers::toInt32($value),
            TypeKind::Int64, TypeKind::Hash64 => LargeInteger::fromJson($value),
            TypeKind::Float32, TypeKind::Float64 => (float) Numbers::toNumber($value),
            TypeKind::Timestamp => self::decodeTimestamp($value),
            TypeKind::String => self::decodeString($value),
            TypeKind::Bytes => self::decodeBytes($value),
            TypeKind::Optional => $value === null ? null : self::decode(TypeValues::itemType($type), $value),
            TypeKind::Array => self::decodeArray($type, $value),
            TypeKind::Struct => self::decodeStruct($type, $value),
            TypeKind::Enum => self::decodeEnum($type, $value),
        };
    }

    private static function encodeBool(mixed $value, JsonFlavor $flavor): bool|int
    {
        if ($flavor === JsonFlavor::Readable) {
            return (bool) $value;
        }

        return $value ? 1 : 0;
    }

    private static function encodeFloat(float $value): float|string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if ($value === INF) {
            return 'Infinity';
        }

        if ($value === -INF) {
            return '-Infinity';
        }

        return $value;
    }

    /**
     * @return int|array{unix_millis: int, formatted: string}
     */
    private static function encodeTimestamp(int $unixMillis, JsonFlavor $flavor): int|array
    {
        if ($flavor === JsonFlavor::Dense) {
            return $unixMillis;
        }

        return [
            'unix_millis' => $unixMillis,
            'formatted' => self::formatIsoTimestamp($unixMillis),
        ];
    }

    private static function formatIsoTimestamp(int $unixMillis): string
    {
        $millis = $unixMillis % 1000;
        $seconds = intdiv($unixMillis, 1000);

        if ($millis < 0) {
            $millis += 1000;
            $seconds--;
        }

        $dateTime = new DateTimeImmutable("@{$seconds}");

        return sprintf('%s-%s.%03dZ', self::formatIsoYear((int) $dateTime->format('Y')), $dateTime->format('m-d\TH:i:s'), $millis);
    }

    private static function formatIsoYear(int $year): string
    {
        if ($year < 0) {
            return sprintf('%+07d', $year);
        }

        if ($year > 9999) {
            return sprintf('%+07d', $year);
        }

        return sprintf('%04d', $year);
    }

    private static function encodeBytes(string $value, JsonFlavor $flavor): string
    {
        if ($flavor === JsonFlavor::Readable) {
            return 'hex:'.bin2hex($value);
        }

        return base64_encode($value);
    }

    /**
     * @return array<int, mixed>
     */
    private static function encodeArray(Type $type, mixed $value, JsonFlavor $flavor): array
    {
        if (! is_array($value)) {
            throw SkirRuntimeException::invalidValue('Skir array values must be PHP arrays.');
        }

        $itemType = TypeValues::itemType($type);

        return array_map(
            fn (mixed $item): mixed => self::encode($itemType, $item, $flavor),
            array_values($value),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, mixed>
     */
    private static function encodeDenseStruct(Type $type, array $value): array
    {
        $slotCount = TypeValues::structSlotCount($type, $value);
        $slots = array_fill(0, $slotCount, 0);

        foreach ($type->fields as $field) {
            if ($field->removed) {
                continue;
            }

            if ($field->number >= $slotCount) {
                break;
            }

            $slots[$field->number] = self::encode(
                TypeValues::fieldType($field),
                TypeValues::fieldValue($field, $value),
                JsonFlavor::Dense,
            );
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|stdClass
     */
    private static function encodeReadableStruct(Type $type, array $value): array|stdClass
    {
        $encodedFields = [];

        foreach ($type->fields as $field) {
            if ($field->removed) {
                continue;
            }

            $fieldType = TypeValues::fieldType($field);
            $fieldValue = TypeValues::fieldValue($field, $value);

            if (TypeValues::isDefault($fieldType, $fieldValue)) {
                continue;
            }

            $encodedFields[$field->name] = self::encode($fieldType, $fieldValue, JsonFlavor::Readable);
        }

        return $encodedFields === [] ? new stdClass : $encodedFields;
    }

    private static function encodeEnum(Type $type, mixed $value, JsonFlavor $flavor): mixed
    {
        if (! $value instanceof EnumValue) {
            throw SkirRuntimeException::invalidValue('Skir enum values must be EnumValue instances.');
        }

        if ($value->name === TypeValues::UNKNOWN_VARIANT) {
            return $flavor === JsonFlavor::Readable ? 'unknown' : 0;
        }

        $variant = TypeValues::variantWithName($type, $value->name);

        if (! $value->wrapper) {
            return $flavor === JsonFlavor::Readable ? strtolower($variant->name) : $variant->number;
        }

        if (! $variant->isWrapper()) {
            throw SkirRuntimeException::invalidValue("Skir enum variant [{$value->name}] does not carry a payload.");
        }

        $payload = self::encode($variant->payloadType, $value->value, $flavor);

        if ($flavor === JsonFlavor::Readable) {
            return ['kind' => $variant->name, 'value' => $payload];
        }

        return [$variant->number, $payload];
    }

    private static function decodeBool(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        return (bool) $value;
    }

    private static function decodeTimestamp(mixed $value): int
    {
        if (is_array($value)) {
            if (! array_key_exists('unix_millis', $value)) {
                throw SkirRuntimeException::invalidValue('Skir readable timestamp JSON values must contain [unix_millis].');
            }

            return Numbers::timestampMillis($value['unix_millis']);
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return Numbers::timestampMillis($value);
        }

        throw SkirRuntimeException::invalidValue('Skir timestamp JSON values must be numbers, strings or objects.');
    }

    private static function decodeString(mixed $value): string
    {
        if (! is_string($value)) {
            throw SkirRuntimeException::invalidValue('Skir string JSON values must be strings.');
        }

        return $value;
    }

    private static function decodeBytes(mixed $value): string
    {
        if (! is_string($value)) {
            throw SkirRuntimeException::invalidValue('Skir bytes JSON values must be strings.');
        }

        if (str_starts_with($value, 'hex:')) {
            return self::decodeHex(substr($value, 4));
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw SkirRuntimeException::invalidValue('Skir bytes values must be valid base64 strings.');
        }

        return $decoded;
    }

    private static function decodeHex(string $hex): string
    {
        $evenLengthHex = substr($hex, 0, intdiv(strlen($hex), 2) * 2);

        if (preg_match('/^[0-9a-fA-F]*$/', $evenLengthHex) !== 1) {
            throw SkirRuntimeException::invalidValue('Skir bytes values prefixed with [hex:] must be valid hexadecimal strings.');
        }

        return (string) hex2bin($evenLengthHex);
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeArray(Type $type, mixed $value): array
    {
        if (! is_array($value)) {
            throw SkirRuntimeException::invalidValue('Skir array JSON values must be arrays.');
        }

        if (! array_is_list($value)) {
            throw SkirRuntimeException::invalidValue('Skir array JSON values must be arrays.');
        }

        $itemType = TypeValues::itemType($type);

        return array_map(
            fn (mixed $item): mixed => self::decode($itemType, $item),
            $value,
        );
    }

    /**
     * Dense structs arrive as lists indexed by field number, readable structs as
     * objects keyed by field name; upstream also accepts field numbers as object keys.
     *
     * @return array<string, mixed>
     */
    private static function decodeStruct(Type $type, mixed $value): array
    {
        if (in_array($value, [null, false, ''], true)) {
            return TypeValues::defaultValue($type);
        }

        if (! is_array($value)) {
            throw SkirRuntimeException::invalidValue('Skir struct JSON values must be arrays or objects.');
        }

        $decoded = TypeValues::defaultValue($type);

        foreach ($value as $key => $fieldValue) {
            $field = TypeValues::fieldWithKey($type, $key);

            if ($field === null) {
                continue;
            }

            $decoded[$field->name] = self::decode(TypeValues::fieldType($field), $fieldValue);
        }

        return $decoded;
    }

    private static function decodeEnum(Type $type, mixed $value): EnumValue
    {
        if (is_array($value)) {
            return self::decodeEnumWithPayload($type, $value);
        }

        if (is_bool($value)) {
            throw SkirRuntimeException::invalidValue('Skir enum JSON values must be numbers, strings, arrays or objects.');
        }

        if ($value === null) {
            throw SkirRuntimeException::invalidValue('Skir enum JSON values must be numbers, strings, arrays or objects.');
        }

        $variant = TypeValues::variantWithKey($type, $value);

        if ($variant === null) {
            return EnumValue::constant(TypeValues::UNKNOWN_VARIANT);
        }

        if ($variant->isWrapper()) {
            return EnumValue::wrapper($variant->name, TypeValues::defaultValue($variant->payloadType));
        }

        return EnumValue::constant($variant->name);
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private static function decodeEnumWithPayload(Type $type, array $value): EnumValue
    {
        $isDense = array_is_list($value);
        $variant = TypeValues::variantWithKey($type, $isDense ? $value[0] ?? null : $value['kind'] ?? null);

        if ($variant === null) {
            return EnumValue::constant(TypeValues::UNKNOWN_VARIANT);
        }

        if (! $variant->isWrapper()) {
            return EnumValue::constant($variant->name);
        }

        $payload = $isDense ? $value[1] ?? null : $value['value'] ?? null;

        return EnumValue::wrapper($variant->name, self::decode($variant->payloadType, $payload));
    }
}
