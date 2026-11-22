<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueAllTypesSubMsg
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

    public function getId(): int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setId(int $value): void
    {
        $this->dirtyValues[1] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x21";
        $enc->writeVarint($value & 0xFFFFFFFF);
        $this->dirty[1] = $enc->finish();
    }

    public function getLabel(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setLabel(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x55";
        $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        $this->dirty[2] = $enc->finish();
    }

    public function getFlag(): bool
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setFlag(bool $value): void
    {
        $this->dirtyValues[3] = $value;
        $enc = new Encoder();
        if ($value) { $enc->buf .= "\x67"; }
        $this->dirty[3] = $enc->finish();
    }

    public function getScore(): int
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getSint64($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setScore(int $value): void
    {
        $this->dirtyValues[4] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x83";
        $enc->writeSintRaw($value);
        $this->dirty[4] = $enc->finish();
    }

}
