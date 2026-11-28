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
        $u = $value & 0xFFFFFFFF;
        $this->dirty[1] = $u !== 0 ? "\x21" . Wire::encodeVarint($u) : '';
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
        $this->dirty[2] = $value !== '' ? "\x55" . Wire::encodeVarint(\strlen($value)) . $value : '';
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
        $this->dirty[3] = $value ? "\x67" : '';
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
        $this->dirty[4] = $value !== 0 ? "\x81" . Wire::encodeVarint($value) : '';
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
        $n = ($value << 32) >> 32;
        $this->dirty[5] = $n !== 0 ? "\xa3" . Wire::encodeVarint(($n << 1) ^ ($n >> 63)) : '';
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
        $this->dirty[6] = ($value !== 0.0 || pack('e', $value) !== "\x00\x00\x00\x00\x00\x00\x00\x00") ? "\xd3" . pack('e', $value) : '';
    }

    /** @return list<string> */
    public function getTags(): array
    {
        if (\array_key_exists(7, $this->dirtyValues)) {
            return $this->dirtyValues[7];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 7, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(10);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readString();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<string> $value */
    public function setTags(array $value): void
    {
        $this->dirtyValues[7] = $value;
        if ($value === []) {
            $this->dirty[7] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\xfb";
        $enc->beginContainer();
        $enc->buf .= "\x0a";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->writeVarint(\strlen($item)); $enc->buf .= $item;
        }
        $enc->endContainer();
        $this->dirty[7] = $enc->finish();
    }

    /** @return list<int> */
    public function getPrefixes(): array
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            return $this->dirtyValues[8];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 8, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(0);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readUint32();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<int> $value */
    public function setPrefixes(array $value): void
    {
        $this->dirtyValues[8] = $value;
        if ($value === []) {
            $this->dirty[8] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\x36\x02";
        $enc->beginContainer();
        $enc->buf .= "\x00";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->writeVarint($item & 0xFFFFFFFF);
        }
        $enc->endContainer();
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
        if (\array_key_exists(9, $this->dirtyValues)) {
            $v = $this->dirtyValues[9];
            if ($v === null) { return null; }
            return new \OmniBuf\Generated\Omnibuf\Bench\OpaqueLocation($v->marshalOmnibuf(true));
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 9);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Bench\OpaqueLocation($slice);
    }

    public function setLocation(?\OmniBuf\Generated\Omnibuf\Bench\Location $value): void
    {
        $this->dirtyValues[9] = $value;
        if ($value !== null) {
            $body = $value->marshalOmnibuf(true);
            $this->dirty[9] = "\x72\x02" . Wire::encodeVarint(\strlen($body)) . $body;
        } else {
            $this->dirty[9] = '';
        }
    }

    /** @return list<\OmniBuf\Generated\Omnibuf\Bench\Neighbor> */
    public function getNeighbors(): array
    {
        if (\array_key_exists(10, $this->dirtyValues)) {
            return $this->dirtyValues[10];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 10, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(12);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = (static function () use ($arrDec): \OmniBuf\Generated\Omnibuf\Bench\Neighbor { $c = new \OmniBuf\Generated\Omnibuf\Bench\Neighbor(); $c->decodeFrom($arrDec->readSubMessageDecoder(), false); return $c; })();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<\OmniBuf\Generated\Omnibuf\Bench\Neighbor> $value */
    public function setNeighbors(array $value): void
    {
        $this->dirtyValues[10] = $value;
        if ($value === []) {
            $this->dirty[10] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\xb6\x02";
        $enc->beginContainer();
        $enc->buf .= "\x0c";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->beginContainer(); $item->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
        }
        $enc->endContainer();
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
        $this->dirty[11] = $value !== 0 ? "\xc2\x02" . Wire::encodeVarint($value) : '';
    }

}
