<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Bench;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaquePeerUpdate
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

    public function getAsn(): int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setAsn(int $value): void
    {
        $this->dirtyValues[1] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x21";
        $enc->writeVarint($value & 0xFFFFFFFF);
        $this->dirty[1] = $enc->finish();
    }

    public function getRouterId(): string
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setRouterId(string $value): void
    {
        $this->dirtyValues[2] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x55";
        $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        $this->dirty[2] = $enc->finish();
    }

    public function getActive(): bool
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setActive(bool $value): void
    {
        $this->dirtyValues[3] = $value;
        $enc = new Encoder();
        if ($value) { $enc->buf .= "\x67"; }
        $this->dirty[3] = $enc->finish();
    }

    public function getUptimeSec(): int
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getUint64($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setUptimeSec(int $value): void
    {
        $this->dirtyValues[4] = $value;
        $enc = new Encoder();
        $enc->buf .= "\x81";
        $enc->writeVarint($value);
        $this->dirty[4] = $enc->finish();
    }

    public function getRssiDbm(): int
    {
        if (\array_key_exists(5, $this->dirtyValues)) {
            return $this->dirtyValues[5];
        }
        return Opaque::getSint32($this->raw, 0, \strlen($this->raw), 5);
    }

    public function setRssiDbm(int $value): void
    {
        $this->dirtyValues[5] = $value;
        $enc = new Encoder();
        $enc->buf .= "\xa3";
        $enc->writeSintRaw(($value << 32) >> 32);
        $this->dirty[5] = $enc->finish();
    }

    public function getCpuLoad(): float
    {
        if (\array_key_exists(6, $this->dirtyValues)) {
            return $this->dirtyValues[6];
        }
        return Opaque::getFloat64($this->raw, 0, \strlen($this->raw), 6);
    }

    public function setCpuLoad(float $value): void
    {
        $this->dirtyValues[6] = $value;
        $enc = new Encoder();
        $enc->buf .= "\xd3";
        $enc->buf .= pack('e', $value);
        $this->dirty[6] = $enc->finish();
    }

    /** @return list<string> */
    public function getTags(): array
    {
        if (\array_key_exists(7, $this->dirtyValues)) {
            return $this->dirtyValues[7];
        }
        $ranges = Opaque::locateAllLengthPrefixed($this->raw, 0, \strlen($this->raw), 7, WireType::ARRAY, 2);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 1);
            $et = \ord($this->raw[$s]);
            if ($et !== 10) { throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray element type mismatch'); }
            $dec->pos = $s + 1;
            $count = $dec->readVarint();
            if ($count === 0) { $dec->assertAtEnd('WireArray'); continue; }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $dec->readString();
            }
            $dec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<string> $value */
    public function setTags(array $value): void
    {
        $this->dirtyValues[7] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $enc->buf .= "\xfb";
            $enc->beginContainer();
            $enc->buf .= "\x0a";
            $enc->writeVarint(\count($value));
            foreach ($value as $item) {
                $enc->writeVarint(\strlen($item)); $enc->buf .= $item;
            }
            $enc->endContainer();
        }
        $this->dirty[7] = $enc->finish();
    }

    /** @return list<int> */
    public function getPrefixes(): array
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            return $this->dirtyValues[8];
        }
        $ranges = Opaque::locateAllLengthPrefixed($this->raw, 0, \strlen($this->raw), 8, WireType::ARRAY, 2);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 1);
            $et = \ord($this->raw[$s]);
            if ($et !== 0) { throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray element type mismatch'); }
            $dec->pos = $s + 1;
            $count = $dec->readVarint();
            if ($count === 0) { $dec->assertAtEnd('WireArray'); continue; }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $dec->readUint32();
            }
            $dec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<int> $value */
    public function setPrefixes(array $value): void
    {
        $this->dirtyValues[8] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $enc->buf .= "\x36\x02";
            $enc->beginContainer();
            $enc->buf .= "\x00";
            $enc->writeVarint(\count($value));
            foreach ($value as $item) {
                $enc->writeVarint($item & 0xFFFFFFFF);
            }
            $enc->endContainer();
        }
        $this->dirty[8] = $enc->finish();
    }

    public function getLocation(): ?\OmniBuf\Generated\Omnibuf\Bench\Location
    {
        if (\array_key_exists(9, $this->dirtyValues)) {
            return $this->dirtyValues[9];
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 9);
        if ($slice === null) { return null; }
        return \OmniBuf\Generated\Omnibuf\Bench\Location::unmarshalOmnibuf($slice);
    }

    public function opaqueLocation(): ?\OmniBuf\Generated\Omnibuf\Bench\OpaqueLocation
    {
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 9);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Bench\OpaqueLocation($slice);
    }

    public function setLocation(?\OmniBuf\Generated\Omnibuf\Bench\Location $value): void
    {
        $this->dirtyValues[9] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x72\x02";
            $enc->beginContainer();
            $value->marshalTo($enc, true);
            $enc->endContainer();
        }
        $this->dirty[9] = $enc->finish();
    }

    /** @return list<\OmniBuf\Generated\Omnibuf\Bench\Neighbor> */
    public function getNeighbors(): array
    {
        if (\array_key_exists(10, $this->dirtyValues)) {
            return $this->dirtyValues[10];
        }
        $ranges = Opaque::locateAllLengthPrefixed($this->raw, 0, \strlen($this->raw), 10, WireType::ARRAY, 2);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 1);
            $et = \ord($this->raw[$s]);
            if ($et !== 12) { throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray element type mismatch'); }
            $dec->pos = $s + 1;
            $count = $dec->readVarint();
            if ($count === 0) { $dec->assertAtEnd('WireArray'); continue; }
            for ($i = 0; $i < $count; $i++) {
                $out[] = (static function () use ($dec): \OmniBuf\Generated\Omnibuf\Bench\Neighbor { $c = new \OmniBuf\Generated\Omnibuf\Bench\Neighbor(); $c->decodeFrom($dec->readSubMessageDecoder(), false); return $c; })();
            }
            $dec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<\OmniBuf\Generated\Omnibuf\Bench\Neighbor> $value */
    public function setNeighbors(array $value): void
    {
        $this->dirtyValues[10] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $enc->buf .= "\xb6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x0c";
            $enc->writeVarint(\count($value));
            foreach ($value as $item) {
                $enc->beginContainer(); $item->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
            }
            $enc->endContainer();
        }
        $this->dirty[10] = $enc->finish();
    }

    public function getSequence(): int
    {
        if (\array_key_exists(11, $this->dirtyValues)) {
            return $this->dirtyValues[11];
        }
        return Opaque::getUint64($this->raw, 0, \strlen($this->raw), 11);
    }

    public function setSequence(int $value): void
    {
        $this->dirtyValues[11] = $value;
        $enc = new Encoder();
        $enc->buf .= "\xc2\x02";
        $enc->writeVarint($value);
        $this->dirty[11] = $enc->finish();
    }

}
