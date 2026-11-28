<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueConformanceResponse
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

    public function getParseError(): string
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setParseError(string $value): void
    {
        $this->dirtyValues[1] = $value;
        $this->dirty[1] = $value !== '' ? "\x35" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getSerializeError(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setSerializeError(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $this->dirty[2] = $value !== '' ? "\x55" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getRuntimeError(): string
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setRuntimeError(string $value): void
    {
        $this->dirtyValues[3] = $value;
        $this->dirty[3] = $value !== '' ? "\x75" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getSkipped(): string
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setSkipped(string $value): void
    {
        $this->dirtyValues[4] = $value;
        $this->dirty[4] = $value !== '' ? "\x95" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getOmnibufPayload(): string
    {
        if (\array_key_exists(5, $this->dirtyValues)) {
            return $this->dirtyValues[5];
        }
        return Opaque::getBytes($this->raw, 0, \strlen($this->raw), 5);
    }

    public function setOmnibufPayload(string $value): void
    {
        $this->dirtyValues[5] = $value;
        $this->dirty[5] = $value !== '' ? "\xb7" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getJsonPayload(): string
    {
        if (\array_key_exists(6, $this->dirtyValues)) {
            return $this->dirtyValues[6];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 6);
    }

    public function setJsonPayload(string $value): void
    {
        $this->dirtyValues[6] = $value;
        $this->dirty[6] = $value !== '' ? "\xd5" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

}
