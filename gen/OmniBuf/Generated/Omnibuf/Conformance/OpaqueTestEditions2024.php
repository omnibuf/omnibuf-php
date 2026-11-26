<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueTestEditions2024
{
    public const int CHOICE_NOT_SET = 0;
    public const int CHOICE_CHOICE_INT = 14;
    public const int CHOICE_CHOICE_TEXT = 15;
    public const int CHOICE_CHOICE_FLAG = 16;
    public const int CHOICE_CHOICE_CHILD = 17;

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

    public function getChoiceCase(): int
    {
        if (\array_key_exists(14, $this->dirtyValues)) {
            if ($this->dirtyValues[14] !== null) { return 14; }
            if ($this->dirtyValues[15] !== null) { return 15; }
            if ($this->dirtyValues[16] !== null) { return 16; }
            if ($this->dirtyValues[17] !== null) { return 17; }
            return 0;
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        return $hit !== null ? $hit[0] : 0;
    }

    public function clearChoice(): void
    {
        $this->dirtyValues[14] = null;
        $this->dirty[14] = '';
        $this->dirtyValues[15] = null;
        $this->dirty[15] = '';
        $this->dirtyValues[16] = null;
        $this->dirty[16] = '';
        $this->dirtyValues[17] = null;
        $this->dirty[17] = '';
    }

    public function getExplicitInt(): ?int
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getSint32OrNull($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setExplicitInt(?int $value): void
    {
        $this->dirtyValues[1] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x23";
            $enc->writeSintRaw(($value << 32) >> 32);
        }
        $this->dirty[1] = $enc->finish();
    }

    public function getExplicitBool(): ?bool
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        return Opaque::getBoolOrNull($this->raw, 0, \strlen($this->raw), 2);
    }

    public function setExplicitBool(?bool $value): void
    {
        $this->dirtyValues[2] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= $value ? "\x47" : "\x45";
        }
        $this->dirty[2] = $enc->finish();
    }

    public function getExplicitString(): ?string
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        return Opaque::getStringOrNull($this->raw, 0, \strlen($this->raw), 3);
    }

    public function setExplicitString(?string $value): void
    {
        $this->dirtyValues[3] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x75";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[3] = $enc->finish();
    }

    public function getExplicitBytes(): ?string
    {
        if (\array_key_exists(4, $this->dirtyValues)) {
            return $this->dirtyValues[4];
        }
        return Opaque::getBytesOrNull($this->raw, 0, \strlen($this->raw), 4);
    }

    public function setExplicitBytes(?string $value): void
    {
        $this->dirtyValues[4] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x97";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[4] = $enc->finish();
    }

    public function getExplicitDouble(): ?float
    {
        if (\array_key_exists(5, $this->dirtyValues)) {
            return $this->dirtyValues[5];
        }
        return Opaque::getFloat64OrNull($this->raw, 0, \strlen($this->raw), 5);
    }

    public function setExplicitDouble(?float $value): void
    {
        $this->dirtyValues[5] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\xb3";
            $enc->buf .= pack('e', $value);
        }
        $this->dirty[5] = $enc->finish();
    }

    public function getStatus(): ?int
    {
        if (\array_key_exists(6, $this->dirtyValues)) {
            return $this->dirtyValues[6];
        }
        return Opaque::getSint32OrNull($this->raw, 0, \strlen($this->raw), 6);
    }

    public function setStatus(?int $value): void
    {
        $this->dirtyValues[6] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\xc3";
            $enc->writeSintRaw(($value << 32) >> 32);
        }
        $this->dirty[6] = $enc->finish();
    }

    public function getImplicitCount(): int
    {
        if (\array_key_exists(7, $this->dirtyValues)) {
            return $this->dirtyValues[7];
        }
        return Opaque::getSint32($this->raw, 0, \strlen($this->raw), 7);
    }

    public function setImplicitCount(int $value): void
    {
        $this->dirtyValues[7] = $value;
        $enc = new Encoder();
        if ((($value << 32) >> 32) !== 0) {
            $enc->buf .= "\xe3";
            $enc->writeSintRaw(($value << 32) >> 32);
        }
        $this->dirty[7] = $enc->finish();
    }

    public function getImplicitFlag(): bool
    {
        if (\array_key_exists(8, $this->dirtyValues)) {
            return $this->dirtyValues[8];
        }
        return Opaque::getBool($this->raw, 0, \strlen($this->raw), 8);
    }

    public function setImplicitFlag(bool $value): void
    {
        $this->dirtyValues[8] = $value;
        $enc = new Encoder();
        if ($value) { $enc->buf .= "\x0e\x02"; }
        $this->dirty[8] = $enc->finish();
    }

    public function getChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child
    {
        if (\array_key_exists(9, $this->dirtyValues)) {
            return $this->dirtyValues[9];
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 9);
        if ($slice === null) { return null; }
        return \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::unmarshalOmnibuf($slice);
    }

    public function opaqueChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child
    {
        if (\array_key_exists(9, $this->dirtyValues)) {
            $v = $this->dirtyValues[9];
            if ($v === null) { return null; }
            return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($v->marshalOmnibuf(true));
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 9);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($slice);
    }

    public function setChild(?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $value): void
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

    public function getDelimitedChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child
    {
        if (\array_key_exists(10, $this->dirtyValues)) {
            return $this->dirtyValues[10];
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 10);
        if ($slice === null) { return null; }
        return \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::unmarshalOmnibuf($slice);
    }

    public function opaqueDelimitedChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child
    {
        if (\array_key_exists(10, $this->dirtyValues)) {
            $v = $this->dirtyValues[10];
            if ($v === null) { return null; }
            return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($v->marshalOmnibuf(true));
        }
        $slice = Opaque::getSubMessageSlice($this->raw, 0, \strlen($this->raw), 10);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($slice);
    }

    public function setDelimitedChild(?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $value): void
    {
        $this->dirtyValues[10] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\xb2\x02";
            $enc->beginContainer();
            $value->marshalTo($enc, true);
            $enc->endContainer();
        }
        $this->dirty[10] = $enc->finish();
    }

    /** @return list<int> */
    public function getPackedVals(): array
    {
        if (\array_key_exists(11, $this->dirtyValues)) {
            return $this->dirtyValues[11];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 11, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(1);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readSint32();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<int> $value */
    public function setPackedVals(array $value): void
    {
        $this->dirtyValues[11] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $enc->buf .= "\xf6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x01";
            $enc->writeVarint(\count($value));
            foreach ($value as $item) {
                $enc->writeSintRaw(($item << 32) >> 32);
            }
            $enc->endContainer();
        }
        $this->dirty[11] = $enc->finish();
    }

    /** @return list<int> */
    public function getExpandedVals(): array
    {
        if (\array_key_exists(12, $this->dirtyValues)) {
            return $this->dirtyValues[12];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 12, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(1);
            if (\count($out) + $count > $arrDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireArray count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readSint32();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /** @param list<int> $value */
    public function setExpandedVals(array $value): void
    {
        $this->dirtyValues[12] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $enc->buf .= "\x36\x03";
            $enc->beginContainer();
            $enc->buf .= "\x01";
            $enc->writeVarint(\count($value));
            foreach ($value as $item) {
                $enc->writeSintRaw(($item << 32) >> 32);
            }
            $enc->endContainer();
        }
        $this->dirty[12] = $enc->finish();
    }

    /** @return array<string, int> */
    public function getScores(): array
    {
        if (\array_key_exists(13, $this->dirtyValues)) {
            return $this->dirtyValues[13];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 13, WireType::MAP);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(10, 1);
            if (\count($out) + $count > $mapDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireMap count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $k = $mapDec->readString();
                $out[$k] = $mapDec->readSint32();
            }
            $mapDec->assertAtEnd('WireMap');
        }
        return $out;
    }

    /** @param array<string, int> $value */
    public function setScores(array $value): void
    {
        $this->dirtyValues[13] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $entries = $value;
            uksort($entries, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
            $enc->buf .= "\x7a\x03";
            $enc->beginContainer();
            $enc->buf .= "\x0a\x01";
            $enc->writeVarint(\count($entries));
            foreach ($entries as $k => $v) {
                $enc->writeVarint(\strlen($k)); $enc->buf .= $k;
                $enc->writeSintRaw(($v << 32) >> 32);
            }
            $enc->endContainer();
        }
        $this->dirty[13] = $enc->finish();
    }

    public function getChoiceInt(): ?int
    {
        if (\array_key_exists(14, $this->dirtyValues)) {
            return $this->dirtyValues[14];
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        if ($hit === null || $hit[0] !== 14) { return null; }
        return Opaque::getSint32OrNull($this->raw, $hit[1], \strlen($this->raw), 14);
    }

    public function setChoiceInt(?int $value): void
    {
        $this->dirtyValues[15] = null;
        $this->dirty[15] = '';
        $this->dirtyValues[16] = null;
        $this->dirty[16] = '';
        $this->dirtyValues[17] = null;
        $this->dirty[17] = '';
        $this->dirtyValues[14] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x86\x03";
            $enc->writeSintRaw(($value << 32) >> 32);
        }
        $this->dirty[14] = $enc->finish();
    }

    public function getChoiceText(): ?string
    {
        if (\array_key_exists(15, $this->dirtyValues)) {
            return $this->dirtyValues[15];
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        if ($hit === null || $hit[0] !== 15) { return null; }
        return Opaque::getStringOrNull($this->raw, $hit[1], \strlen($this->raw), 15);
    }

    public function setChoiceText(?string $value): void
    {
        $this->dirtyValues[14] = null;
        $this->dirty[14] = '';
        $this->dirtyValues[16] = null;
        $this->dirty[16] = '';
        $this->dirtyValues[17] = null;
        $this->dirty[17] = '';
        $this->dirtyValues[15] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\xea\x03";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[15] = $enc->finish();
    }

    public function getChoiceFlag(): ?bool
    {
        if (\array_key_exists(16, $this->dirtyValues)) {
            return $this->dirtyValues[16];
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        if ($hit === null || $hit[0] !== 16) { return null; }
        return Opaque::getBoolOrNull($this->raw, $hit[1], \strlen($this->raw), 16);
    }

    public function setChoiceFlag(?bool $value): void
    {
        $this->dirtyValues[14] = null;
        $this->dirty[14] = '';
        $this->dirtyValues[15] = null;
        $this->dirty[15] = '';
        $this->dirtyValues[17] = null;
        $this->dirty[17] = '';
        $this->dirtyValues[16] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= $value ? "\x0e\x04" : "\x0a\x04";
        }
        $this->dirty[16] = $enc->finish();
    }

    public function getChoiceChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child
    {
        if (\array_key_exists(17, $this->dirtyValues)) {
            return $this->dirtyValues[17];
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        if ($hit === null || $hit[0] !== 17) { return null; }
        $slice = Opaque::getSubMessageSlice($this->raw, $hit[1], \strlen($this->raw), 17);
        if ($slice === null) { return null; }
        return \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::unmarshalOmnibuf($slice);
    }

    public function opaqueChoiceChild(): ?\OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child
    {
        if (\array_key_exists(17, $this->dirtyValues)) {
            $v = $this->dirtyValues[17];
            if ($v === null) { return null; }
            return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($v->marshalOmnibuf(true));
        }
        $hit = Opaque::locateLastOneof($this->raw, 0, \strlen($this->raw), [14 => 1, 15 => 10, 16 => 2, 17 => 12]);
        if ($hit === null || $hit[0] !== 17) { return null; }
        $slice = Opaque::getSubMessageSlice($this->raw, $hit[1], \strlen($this->raw), 17);
        if ($slice === null) { return null; }
        return new \OmniBuf\Generated\Omnibuf\Conformance\OpaqueEditions2024Child($slice);
    }

    public function setChoiceChild(?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $value): void
    {
        $this->dirtyValues[14] = null;
        $this->dirty[14] = '';
        $this->dirtyValues[15] = null;
        $this->dirty[15] = '';
        $this->dirtyValues[16] = null;
        $this->dirty[16] = '';
        $this->dirtyValues[17] = $value;
        $enc = new Encoder();
        if ($value !== null) {
            $enc->buf .= "\x72\x04";
            $enc->beginContainer();
            $value->marshalTo($enc, true);
            $enc->endContainer();
        }
        $this->dirty[17] = $enc->finish();
    }

}
