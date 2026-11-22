<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

/**
 * @internal Value wrapper for raw byte strings inside BlindDecoder.
 */
final readonly class BlindBytes
{
    public function __construct(
        public string $bytes,
    ) {}
}
