<?php

declare(strict_types=1);

namespace OmniBuf\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OmniBufMessage
{
    public function __construct(
        public string $name = '',
    ) {}
}
