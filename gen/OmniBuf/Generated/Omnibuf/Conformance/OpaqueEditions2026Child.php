<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueEditions2026Child
{
    /** @var array<int, string> */
    private array $dirty = [];

    /** @var array<int, mixed> */
    private array $dirtyValues = [];

    public function __construct(
        private readonly string $raw,
    ) {}

    public function marshal(): string
    {
        return Wire::spliceFields('', $this->raw, $this->dirty);
    }

    public function marshalAppend(string $prefix = ''): string
    {
        return Wire::spliceFields($prefix, $this->raw, $this->dirty);
    }

    public function getId(): ?int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getSint32OrNull($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setId(?int $value): void
    {
        $this->dirtyValues[1] = $value;
        if ($value === null) {
            $this->dirty[1] = '';
            return;
        }
        $this->dirty[1] = "\x23" . Wire::encodeVarint(((($value << 32) >> 32) << 1) ^ ((($value << 32) >> 32) >> 63));
    }

    public function getNote(): ?string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getStringOrNull($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setNote(?string $value): void
    {
        $this->dirtyValues[2] = $value;
        if ($value === null) {
            $this->dirty[2] = '';
            return;
        }
        $this->dirty[2] = "\x55" . Wire::encodeVarint(\strlen($value)) . $value;
    }

}
