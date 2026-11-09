<?php

declare(strict_types=1);

namespace Skir\Runtime;

use JsonException;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Internal\JsonCodec;
use Skir\Runtime\Internal\JsonFlavor;
use Skir\Runtime\Internal\JsonWriter;

final class ReadableJson
{
    private const string INDENT = '  ';

    /**
     * Struct values become associative arrays keyed by field name, or an empty
     * stdClass when every field holds its default value so it encodes as {}.
     */
    public static function encode(Type $type, mixed $value): mixed
    {
        return JsonCodec::encode($type, $value, JsonFlavor::Readable);
    }

    public static function toJson(Type $type, mixed $value): string
    {
        try {
            return JsonWriter::write(self::encode($type, $value), self::INDENT);
        } catch (JsonException $exception) {
            throw SkirRuntimeException::invalidReadableJson($exception->getMessage(), $exception);
        }
    }
}
