<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

/**
 * @internal Value wrapper for decoded OmniBuf messages and maps inside BlindDecoder.
 */
final readonly class BlindObject
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public array $fields,
    ) {}
}
