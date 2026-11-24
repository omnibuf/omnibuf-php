<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

use OmniBuf\Exception\DecodeException;

/**
 * Shared mutable cumulative element budget across a single packet decode.
 *
 * @internal
 */
final class ElementBudget
{
    public int $remaining;

    public function __construct(public readonly int $limit)
    {
        $this->remaining = $limit;
    }

    public function consume(int $count, string $context = 'container'): void
    {
        if ($count < 0 || $count > $this->remaining) {
            throw new DecodeException("omnibuf: {$context} element count exceeds maxElements budget {$this->limit}");
        }
        $this->remaining -= $count;
    }
}
