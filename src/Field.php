<?php

declare(strict_types=1);

namespace Skir\Runtime;

final readonly class Field
{
    private function __construct(
        public ?string $name,
        public int $number,
        public ?Type $type,
        public bool $removed,
        public ?string $doc = null,
    ) {}

    public static function value(string $name, int $number, Type $type, ?string $doc = null): self
    {
        return new self($name, $number, $type, false, $doc);
    }

    public static function removed(int $number): self
    {
        return new self(null, $number, null, true);
    }
}
