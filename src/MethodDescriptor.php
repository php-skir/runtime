<?php

declare(strict_types=1);

namespace Skir\Runtime;

final readonly class MethodDescriptor
{
    public function __construct(
        public string $name,
        public int $number,
        public Type $requestType,
        public Type $responseType,
        public ?string $doc = null,
    ) {}
}
