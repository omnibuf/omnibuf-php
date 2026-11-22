<?php

declare(strict_types=1);

namespace OmniBuf\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OmniBufField
{
    public function __construct(
        public int $number,
        public string $wire = '',
        public string $name = '',
        public ?string $elementWire = null,
        public ?string $keyWire = null,
        public ?string $valueWire = null,
        public ?string $messageClass = null,
        public bool $omitEmpty = false,
    ) {}
}
