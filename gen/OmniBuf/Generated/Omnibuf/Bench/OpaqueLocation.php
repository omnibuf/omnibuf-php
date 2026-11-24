<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Bench;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueLocation
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

    public function getRegion(): string
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setRegion(string $value): void
    {
        $this->dirtyValues[1] = $value;
        $enc = new Encoder();
        if ($value !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[1] = $enc->finish();
    }

    public function getDatacenter(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setDatacenter(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $enc = new Encoder();
        if ($value !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[2] = $enc->finish();
    }

    public function getCity(): string
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setCity(string $value): void
    {
        $this->dirtyValues[3] = $value;
        $enc = new Encoder();
        if ($value !== '') {
            $enc->buf .= "\x75";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[3] = $enc->finish();
    }

    public function getRack(): int
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setRack(int $value): void
    {
        $this->dirtyValues[4] = $value;
        $enc = new Encoder();
        if (($value & 0xFFFFFFFF) !== 0) {
            $enc->buf .= "\x81";
            $enc->writeVarint($value & 0xFFFFFFFF);
        }
        $this->dirty[4] = $enc->finish();
    }

    public function getLatitude(): float
    {
        if (\array_key_exists(5, $this->dirtyValues)) {
            return $this->dirtyValues[5];
        }
        return Opaque::getFloat32($this->raw, 0, \strlen($this->raw), 5);
    }

    public function setLatitude(float $value): void
    {
        $this->dirtyValues[5] = $value;
        $enc = new Encoder();
        if (pack('g', $value) !== "\x00\x00\x00\x00") {
            $enc->buf .= "\xb1";
            $enc->buf .= pack('g', $value);
        }
        $this->dirty[5] = $enc->finish();
    }

    public function getLongitude(): float
    {
        if (\array_key_exists(6, $this->dirtyValues)) {
            return $this->dirtyValues[6];
        }
        return Opaque::getFloat32($this->raw, 0, \strlen($this->raw), 6);
    }

    public function setLongitude(float $value): void
    {
        $this->dirtyValues[6] = $value;
        $enc = new Encoder();
        if (pack('g', $value) !== "\x00\x00\x00\x00") {
            $enc->buf .= "\xd1";
            $enc->buf .= pack('g', $value);
        }
        $this->dirty[6] = $enc->finish();
    }

}
