<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Bench;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueNeighbor
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

    public function getPeerAsn(): int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setPeerAsn(int $value): void
    {
        $this->dirtyValues[1] = $value;
        $u = $value & 0xFFFFFFFF;
        $this->dirty[1] = $u !== 0 ? "\x21" . Wire::encodeVarint($u) : '';
    }

    public function getRouter(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setRouter(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $this->dirty[2] = $value !== '' ? "\x55" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getLatencyUs(): int
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setLatencyUs(int $value): void
    {
        $this->dirtyValues[3] = $value;
        $u = $value & 0xFFFFFFFF;
        $this->dirty[3] = $u !== 0 ? "\x61" . Wire::encodeVarint($u) : '';
    }

    public function getEstablished(): bool
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setEstablished(bool $value): void
    {
        $this->dirtyValues[4] = $value;
        $this->dirty[4] = $value ? "\x87" : '';
    }

}
