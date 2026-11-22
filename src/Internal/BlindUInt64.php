<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

/**
 * @internal Value wrapper for unsigned 64-bit integers exceeding PHP_INT_MAX inside BlindDecoder.
 */
final readonly class BlindUInt64
{
    public function __construct(
        public string $decimal,
    ) {}
}
