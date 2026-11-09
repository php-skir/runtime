<?php

declare(strict_types=1);

namespace Skir\Runtime\Internal;

use Skir\Runtime\Exceptions\SkirRuntimeException;

/** @internal */
final class BinaryReader
{
    public function __construct(
        private readonly string $bytes,
        private int $offset = 0,
    ) {}

    public function peekUint8(): int
    {
        $this->requireAvailable(1);

        return ord($this->bytes[$this->offset]);
    }

    public function readUint8(): int
    {
        $byte = $this->peekUint8();
        $this->offset++;

        return $byte;
    }

    public function read(int $length): string
    {
        $this->requireAvailable($length);
        $bytes = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    /**
     * @return int|float
     */
    public function readFormatted(string $unpackFormat, int $length): mixed
    {
        return unpack($unpackFormat, $this->read($length))[1];
    }

    public function remaining(): int
    {
        return strlen($this->bytes) - $this->offset;
    }

    private function requireAvailable(int $length): void
    {
        if ($length > $this->remaining()) {
            throw SkirRuntimeException::invalidBinary('Truncated binary payload');
        }
    }
}
