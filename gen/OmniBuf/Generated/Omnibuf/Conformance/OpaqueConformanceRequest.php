<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueConformanceRequest
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

    public function getTestName(): string
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setTestName(string $value): void
    {
        $this->dirtyValues[1] = $value;
        $this->dirty[1] = $value !== '' ? "\x35" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getMessageType(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setMessageType(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $this->dirty[2] = $value !== '' ? "\x55" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getTier(): int
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getSint32($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setTier(int $value): void
    {
        $this->dirtyValues[3] = $value;
        $n = ($value << 32) >> 32;
        $this->dirty[3] = $n !== 0 ? "\x63" . Wire::encodeVarint(($n << 1) ^ ($n >> 63)) : '';
    }

    public function getRequestedOutputFormat(): int
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getSint32($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setRequestedOutputFormat(int $value): void
    {
        $this->dirtyValues[4] = $value;
        $n = ($value << 32) >> 32;
        $this->dirty[4] = $n !== 0 ? "\x83" . Wire::encodeVarint(($n << 1) ^ ($n >> 63)) : '';
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

    public function getSelfDescribing(): bool
    {
        if (\array_key_exists(7, $this->dirtyValues)) {
            return $this->dirtyValues[7];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 7);
    }

    public function setSelfDescribing(bool $value): void
    {
        $this->dirtyValues[7] = $value;
        $this->dirty[7] = $value ? "\xe7" : '';
    }

    public function getTestOpaqueSplice(): bool
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            return $this->dirtyValues[8];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 8);
    }

    public function setTestOpaqueSplice(bool $value): void
    {
        $this->dirtyValues[8] = $value;
        $this->dirty[8] = $value ? "\x0e\x02" : '';
    }

    public function getTestOpaqueSpliceShrink(): bool
    {
        if (\array_key_exists(9, $this->dirtyValues)) {
            return $this->dirtyValues[9];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 9);
    }

    public function setTestOpaqueSpliceShrink(bool $value): void
    {
        $this->dirtyValues[9] = $value;
        $this->dirty[9] = $value ? "\x4e\x02" : '';
    }

}
