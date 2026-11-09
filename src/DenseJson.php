<?php

declare(strict_types=1);

namespace Skir\Runtime;

use JsonException;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Internal\JsonCodec;
use Skir\Runtime\Internal\JsonFlavor;
use Skir\Runtime\Internal\JsonWriter;

final class DenseJson
{
    public static function toJson(Type $type, mixed $value): string
    {
        try {
            return JsonWriter::write(self::encode($type, $value));
        } catch (JsonException $exception) {
            throw SkirRuntimeException::invalidDenseJson($exception->getMessage(), $exception);
        }
    }

    /**
     * Accepts both the dense and the readable JSON flavor.
     */
    public static function fromJson(Type $type, string $json): mixed
    {
        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SkirRuntimeException::invalidDenseJson($exception->getMessage(), $exception);
        }

        return self::decode($type, $value);
    }

    public static function encode(Type $type, mixed $value): mixed
    {
        return JsonCodec::encode($type, $value, JsonFlavor::Dense);
    }

    /**
     * Accepts both the dense and the readable JSON flavor.
     */
    public static function decode(Type $type, mixed $value): mixed
    {
        return JsonCodec::decode($type, $value);
    }
}
