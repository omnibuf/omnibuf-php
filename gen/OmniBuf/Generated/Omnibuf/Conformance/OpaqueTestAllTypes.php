<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueTestAllTypes
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

    public function getF1Uint32(): int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getUint32($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setF1Uint32(int $value): void
    {
        $this->dirtyValues[1] = $value;
        $u = $value & 0xFFFFFFFF;
        $this->dirty[1] = $u !== 0 ? "\x21" . Wire::encodeVarint($u) : '';
    }

    public function getF2Uint64(): int
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getUint64($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setF2Uint64(int $value): void
    {
        $this->dirtyValues[2] = $value;
        $this->dirty[2] = $value !== 0 ? "\x41" . Wire::encodeVarint($value) : '';
    }

    public function getF3Sint32(): int
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getSint32($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setF3Sint32(int $value): void
    {
        $this->dirtyValues[3] = $value;
        $n = ($value << 32) >> 32;
        $this->dirty[3] = $n !== 0 ? "\x63" . Wire::encodeVarint(($n << 1) ^ ($n >> 63)) : '';
    }

    public function getF4Sint64(): int
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getSint64($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setF4Sint64(int $value): void
    {
        $this->dirtyValues[4] = $value;
        $this->dirty[4] = $value !== 0 ? "\x83" . Wire::encodeVarint(($value << 1) ^ ($value >> 63)) : '';
    }

    public function getF5Bool(): bool
    {
        if (\array_key_exists(5, $this->dirtyValues)) {
            return $this->dirtyValues[5];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 5);
    }

    public function setF5Bool(bool $value): void
    {
        $this->dirtyValues[5] = $value;
        $this->dirty[5] = $value ? "\xa7" : '';
    }

    public function getF6String(): string
    {
        if (\array_key_exists(6, $this->dirtyValues)) {
            return $this->dirtyValues[6];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 6);
    }

    public function setF6String(string $value): void
    {
        $this->dirtyValues[6] = $value;
        $this->dirty[6] = $value !== '' ? "\xd5" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getF7Bytes(): string
    {
        if (\array_key_exists(7, $this->dirtyValues)) {
            return $this->dirtyValues[7];
        }
        return Opaque::getBytes($this->raw, 0, \strlen($this->raw), 7);
    }

    public function setF7Bytes(string $value): void
    {
        $this->dirtyValues[7] = $value;
        $this->dirty[7] = $value !== '' ? "\xf7" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

    public function getF8SubMsg(): ?\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            return $this->dirtyValues[8];
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 8);
        if ($slice === null) { return null; }
        return \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg::unmarshalOmnibuf($slice);
    }

    public function opaqueF8SubMsg(): ?\OmniBuf\Generated\Omnibuf\Conformance\OpaqueAllTypesSubMsg
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            $v = $this->dirtyValues[8];
            if ($v === null) { return null; }
            return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueAllTypesSubMsg($v->marshalOmnibuf(true));
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 8);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueAllTypesSubMsg($slice);
    }

    public function setF8SubMsg(?\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg $value): void
    {
        $this->dirtyValues[8] = $value;
        if ($value !== null) {
            $body = $value->marshalOmnibuf(true);
            $this->dirty[8] = "\x32\x02" . Wire::encodeVarint(\strlen($body)) . $body;
        } else {
            $this->dirty[8] = '';
        }
    }

    /** @return list<string> */
    public function getF9StringArr(): array
    {
        if (\array_key_exists(9, $this->dirtyValues)) {
            return $this->dirtyValues[9];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 9, WireType::ARRAY);
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
    public function setF9StringArr(array $value): void
    {
        $this->dirtyValues[9] = $value;
        if ($value === []) {
            $this->dirty[9] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\x76\x02";
        $enc->beginContainer();
        $enc->buf .= "\x0a";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->writeVarint(\strlen($item)); $enc->buf .= $item;
        }
        $enc->endContainer();
        $this->dirty[9] = $enc->finish();
    }

    /** @return list<int> */
    public function getF10Uint32Arr(): array
    {
        if (\array_key_exists(10, $this->dirtyValues)) {
            return $this->dirtyValues[10];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 10, WireType::ARRAY);
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
    public function setF10Uint32Arr(array $value): void
    {
        $this->dirtyValues[10] = $value;
        if ($value === []) {
            $this->dirty[10] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\xb6\x02";
        $enc->beginContainer();
        $enc->buf .= "\x00";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->writeVarint($item & 0xFFFFFFFF);
        }
        $enc->endContainer();
        $this->dirty[10] = $enc->finish();
    }

    /** @return list<\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg> */
    public function getF11SubMsgArr(): array
    {
        if (\array_key_exists(11, $this->dirtyValues)) {
            return $this->dirtyValues[11];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 11, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(12);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = (static function () use ($arrDec): \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg { $c = new \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg(); $c->decodeFrom($arrDec->readSubMessageDecoder(), false); return $c; })();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg> $value */
    public function setF11SubMsgArr(array $value): void
    {
        $this->dirtyValues[11] = $value;
        if ($value === []) {
            $this->dirty[11] = '';
            return;
        }
        $enc = new Encoder();
        $enc->buf .= "\xf6\x02";
        $enc->beginContainer();
        $enc->buf .= "\x0c";
        $enc->writeVarint(\count($value));
        foreach ($value as $item) {
            $enc->beginContainer(); $item->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
        }
        $enc->endContainer();
        $this->dirty[11] = $enc->finish();
    }

    /** @return array<string, int> */
    public function getF12MapStrUint(): array
    {
        if (\array_key_exists(12, $this->dirtyValues)) {
            return $this->dirtyValues[12];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 12, WireType::MAP);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(10, 0);
            if (\count($out) + $count > $mapDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireMap count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $k = $mapDec->readString();
                $out[$k] = $mapDec->readUint64();
            }
            $mapDec->assertAtEnd('WireMap');
        }
        return $out;
    }

    /** @param array<string, int> $value */
    public function setF12MapStrUint(array $value): void
    {
        $this->dirtyValues[12] = $value;
        if ($value === []) {
            $this->dirty[12] = '';
            return;
        }
        $enc = new Encoder();
        $entries = $value;
        uksort($entries, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
        $enc->buf .= "\x3a\x03";
        $enc->beginContainer();
        $enc->buf .= "\x0a\x00";
        $enc->writeVarint(\count($entries));
        foreach ($entries as $k => $v) {
            $enc->writeVarint(\strlen($k)); $enc->buf .= $k;
            $enc->writeVarint($v);
        }
        $enc->endContainer();
        $this->dirty[12] = $enc->finish();
    }

    public function getF13Float32(): float
    {
        if (\array_key_exists(13, $this->dirtyValues)) {
            return $this->dirtyValues[13];
        }
        return Opaque::getFloat32($this->raw, 0, \strlen($this->raw), 13);
    }

    public function setF13Float32(float $value): void
    {
        $this->dirtyValues[13] = $value;
        $p = pack('g', $value);
        $this->dirty[13] = $p !== "\x00\x00\x00\x00" ? "\x62\x03" . $p : '';
    }

    public function getF14Float64(): float
    {
        if (\array_key_exists(14, $this->dirtyValues)) {
            return $this->dirtyValues[14];
        }
        return Opaque::getFloat64($this->raw, 0, \strlen($this->raw), 14);
    }

    public function setF14Float64(float $value): void
    {
        $this->dirtyValues[14] = $value;
        $this->dirty[14] = ($value !== 0.0 || pack('e', $value) !== "\x00\x00\x00\x00\x00\x00\x00\x00") ? "\xa6\x03" . pack('e', $value) : '';
    }

    public function getF15Fixed32(): int
    {
        if (\array_key_exists(15, $this->dirtyValues)) {
            return $this->dirtyValues[15];
        }
        return Opaque::getFixed32($this->raw, 0, \strlen($this->raw), 15);
    }

    public function setF15Fixed32(int $value): void
    {
        $this->dirtyValues[15] = $value;
        $u = $value & 0xFFFFFFFF;
        $this->dirty[15] = $u !== 0 ? "\xd2\x03" . pack('V', $u) : '';
    }

    public function getF16Fixed64(): int
    {
        if (\array_key_exists(16, $this->dirtyValues)) {
            return $this->dirtyValues[16];
        }
        return Opaque::getFixed64($this->raw, 0, \strlen($this->raw), 16);
    }

    public function setF16Fixed64(int $value): void
    {
        $this->dirtyValues[16] = $value;
        $this->dirty[16] = $value !== 0 ? "\x16\x04" . pack('P', $value) : '';
    }

    public function getF20OverflowStr(): string
    {
        if (\array_key_exists(20, $this->dirtyValues)) {
            return $this->dirtyValues[20];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 20);
    }

    public function setF20OverflowStr(string $value): void
    {
        $this->dirtyValues[20] = $value;
        $this->dirty[20] = $value !== '' ? "\x2a\x05" . Wire::encodeVarint(\strlen($value)) . $value : '';
    }

}
