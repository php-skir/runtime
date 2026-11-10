<?php

declare(strict_types=1);

namespace Skir\Runtime;

final readonly class Type
{
    /**
     * @param  array<int, Field>  $fields
     * @param  array<int, Variant>  $variants
     */
    private function __construct(
        public TypeKind $kind,
        public ?Type $itemType = null,
        public array $fields = [],
        public array $variants = [],
        public ?string $recordId = null,
        public ?string $doc = null,
    ) {}

    public static function bool(): self
    {
        return new self(TypeKind::Bool);
    }

    public static function int32(): self
    {
        return new self(TypeKind::Int32);
    }

    public static function int64(): self
    {
        return new self(TypeKind::Int64);
    }

    public static function hash64(): self
    {
        return new self(TypeKind::Hash64);
    }

    public static function float32(): self
    {
        return new self(TypeKind::Float32);
    }

    public static function float64(): self
    {
        return new self(TypeKind::Float64);
    }

    public static function timestamp(): self
    {
        return new self(TypeKind::Timestamp);
    }

    public static function string(): self
    {
        return new self(TypeKind::String);
    }

    public static function bytes(): self
    {
        return new self(TypeKind::Bytes);
    }

    public static function optional(Type $itemType): self
    {
        return new self(TypeKind::Optional, itemType: $itemType);
    }

    public static function array(Type $itemType): self
    {
        return new self(TypeKind::Array, itemType: $itemType);
    }

    /**
     * `$recordId` is the record's identity in the schema, `path/to/module.skir:Qualified.Name`.
     * It and `$doc` only describe the type; neither affects serialization.
     *
     * @param  array<int, Field>  $fields
     */
    public static function struct(array $fields, ?string $recordId = null, ?string $doc = null): self
    {
        usort($fields, fn (Field $left, Field $right): int => $left->number <=> $right->number);

        return new self(TypeKind::Struct, fields: $fields, recordId: $recordId, doc: $doc);
    }

    /**
     * @param  array<int, Variant>  $variants
     */
    public static function enum(array $variants, ?string $recordId = null, ?string $doc = null): self
    {
        usort($variants, fn (Variant $left, Variant $right): int => $left->number <=> $right->number);

        return new self(TypeKind::Enum, variants: $variants, recordId: $recordId, doc: $doc);
    }
}
