<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use Skir\Runtime\EnumValue;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Field;
use Skir\Runtime\Type;
use Skir\Runtime\TypeKind;
use Skir\Runtime\Variant;
use Stringable;

/**
 * Format-independent knowledge about Skir types shared by every serializer.
 *
 * @internal
 */
final class TypeValues
{
    public const string UNKNOWN_VARIANT = 'UNKNOWN';

    public static function defaultValue(Type $type): mixed
    {
        return match ($type->kind) {
            TypeKind::Bool => false,
            TypeKind::Int32, TypeKind::Int64, TypeKind::Hash64, TypeKind::Timestamp => 0,
            TypeKind::Float32, TypeKind::Float64 => 0.0,
            TypeKind::String, TypeKind::Bytes => '',
            TypeKind::Optional => null,
            TypeKind::Array => [],
            TypeKind::Struct => self::defaultStructValue($type),
            TypeKind::Enum => EnumValue::constant(self::UNKNOWN_VARIANT),
        };
    }

    public static function requireString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw SkirRuntimeException::invalidValue('Expected a string, got '.get_debug_type($value).'.');
    }

    public static function isDefault(Type $type, mixed $value): bool
    {
        return match ($type->kind) {
            TypeKind::Bool => ! $value,
            TypeKind::Int32, TypeKind::Float32, TypeKind::Float64 => Numbers::isZero($value),
            TypeKind::Int64, TypeKind::Hash64 => LargeInteger::normalize($value) === 0,
            TypeKind::Timestamp => Numbers::timestampMillis($value) === 0,
            TypeKind::String, TypeKind::Bytes => self::requireString($value) === '',
            TypeKind::Optional => $value === null,
            TypeKind::Array => $value === [],
            TypeKind::Struct => self::structSlotCount($type, self::requireStructValue($value)) === 0,
            TypeKind::Enum => $value instanceof EnumValue && $value->name === self::UNKNOWN_VARIANT,
        };
    }

    /**
     * Number of slots needed to encode the struct: one past the highest field holding a non-default value.
     *
     * @param  array<string, mixed>  $value
     */
    public static function structSlotCount(Type $type, array $value): int
    {
        foreach (array_reverse($type->fields) as $field) {
            if ($field->removed) {
                continue;
            }

            if (! self::isDefault(self::fieldType($field), self::fieldValue($field, $value))) {
                return $field->number + 1;
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireStructValue(mixed $value): array
    {
        if (! is_array($value)) {
            throw SkirRuntimeException::invalidValue('Skir struct values must be associative arrays.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fieldValue(Field $field, array $value): mixed
    {
        return array_key_exists($field->name, $value)
            ? $value[$field->name]
            : self::defaultValue(self::fieldType($field));
    }

    public static function itemType(Type $type): Type
    {
        return $type->itemType
            ?? throw SkirRuntimeException::invalidValue("Skir [{$type->kind->value}] type does not define an item type.");
    }

    public static function fieldType(Field $field): Type
    {
        return $field->type
            ?? throw SkirRuntimeException::invalidValue("Skir field [{$field->number}] does not define a type.");
    }

    public static function fieldWithKey(Type $type, int|string $key): ?Field
    {
        foreach ($type->fields as $field) {
            if ($field->removed) {
                continue;
            }

            if ($field->number === $key) {
                return $field;
            }

            if ($field->name === $key) {
                return $field;
            }
        }

        return null;
    }

    public static function variantWithName(Type $type, string $name): Variant
    {
        foreach ($type->variants as $variant) {
            if ($variant->name === $name) {
                return $variant;
            }
        }

        throw SkirRuntimeException::invalidValue("Skir enum variant [{$name}] is not defined.");
    }

    public static function variantWithNumber(Type $type, int $number): ?Variant
    {
        foreach ($type->variants as $variant) {
            if ($variant->number === $number) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Resolves a variant the way upstream resolves JSON enum keys: by number, by
     * exact name, or by the upper or lower case name of a constant variant.
     */
    public static function variantWithKey(Type $type, mixed $key): ?Variant
    {
        if (is_int($key)) {
            return self::variantWithNumber($type, $key);
        }

        if (is_float($key)) {
            return self::variantWithIntegralFloat($type, $key);
        }

        if (! is_string($key)) {
            return null;
        }

        if (preg_match('/^(0|-?[1-9]\d*)$/', $key) === 1) {
            return self::variantWithNumber($type, (int) $key);
        }

        return self::variantWithReadableName($type, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultStructValue(Type $type): array
    {
        $defaults = [];

        foreach ($type->fields as $field) {
            if ($field->removed) {
                continue;
            }

            $defaults[$field->name] = self::defaultValue(self::fieldType($field));
        }

        return $defaults;
    }

    private static function variantWithIntegralFloat(Type $type, float $key): ?Variant
    {
        if (floor($key) !== $key) {
            return null;
        }

        if (abs($key) > Numbers::MAX_SAFE_INTEGER) {
            return null;
        }

        return self::variantWithNumber($type, (int) $key);
    }

    private static function variantWithReadableName(Type $type, string $name): ?Variant
    {
        foreach ($type->variants as $variant) {
            if ($variant->name === $name) {
                return $variant;
            }

            if ($variant->isWrapper()) {
                continue;
            }

            if (strtolower($variant->name) === $name) {
                return $variant;
            }

            if (strtoupper($variant->name) === $name) {
                return $variant;
            }
        }

        return null;
    }
}
