<?php

declare(strict_types=1);

namespace Skir\Runtime;

final readonly class Variant
{
    private function __construct(
        public string $name,
        public int $number,
        public ?Type $payloadType,
        public ?string $doc = null,
    ) {}

    public static function constant(string $name, int $number, ?string $doc = null): self
    {
        return new self($name, $number, null, $doc);
    }

    public static function wrapper(string $name, int $number, Type $payloadType, ?string $doc = null): self
    {
        return new self($name, $number, $payloadType, $doc);
    }

    public function isWrapper(): bool
    {
        return $this->payloadType !== null;
    }
}
