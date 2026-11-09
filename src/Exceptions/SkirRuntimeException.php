<?php

declare(strict_types=1);

namespace Skir\Runtime\Exceptions;

use InvalidArgumentException;
use Throwable;

final class SkirRuntimeException extends InvalidArgumentException
{
    public static function invalidDenseJson(string $message, ?Throwable $previous = null): self
    {
        return new self("Invalid dense JSON: {$message}", previous: $previous);
    }

    public static function invalidReadableJson(string $message, ?Throwable $previous = null): self
    {
        return new self("Invalid readable JSON: {$message}", previous: $previous);
    }

    public static function invalidValue(string $message): self
    {
        return new self($message);
    }
}
