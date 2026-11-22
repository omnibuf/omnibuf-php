<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\GeneratedMessage;
use OmniBuf\SchemaMeta;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class TestAllTypes implements GeneratedMessage
{
    public int $f1Uint32 = 0;

    public int $f2Uint64 = 0;

    public int $f3Sint32 = 0;

    public int $f4Sint64 = 0;

    public bool $f5Bool = false;

    public string $f6String = '';

    public string $f7Bytes = '';

    public ?\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg $f8SubMsg = null;

    /** @var list<string> */
    public array $f9StringArr = [];

    /** @var list<int> */
    public array $f10Uint32Arr = [];

    /** @var list<\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg> */
    public array $f11SubMsgArr = [];

    /** @var array<string, int> */
    public array $f12MapStrUint = [];

    public float $f13Float32 = 0.0;

    public float $f14Float64 = 0.0;

    public int $f15Fixed32 = 0;

    public int $f16Fixed64 = 0;

    public string $f20OverflowStr = '';

    public function __construct(
        ?int $f1Uint32 = null,
        ?int $f2Uint64 = null,
        ?int $f3Sint32 = null,
        ?int $f4Sint64 = null,
        ?bool $f5Bool = null,
        ?string $f6String = null,
        ?string $f7Bytes = null,
        ?\OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg $f8SubMsg = null,
        ?array $f9StringArr = null,
        ?array $f10Uint32Arr = null,
        ?array $f11SubMsgArr = null,
        ?array $f12MapStrUint = null,
        ?float $f13Float32 = null,
        ?float $f14Float64 = null,
        ?int $f15Fixed32 = null,
        ?int $f16Fixed64 = null,
        ?string $f20OverflowStr = null,
    ) {
        if ($f1Uint32 !== null) { $this->f1Uint32 = $f1Uint32; }
        if ($f2Uint64 !== null) { $this->f2Uint64 = $f2Uint64; }
        if ($f3Sint32 !== null) { $this->f3Sint32 = $f3Sint32; }
        if ($f4Sint64 !== null) { $this->f4Sint64 = $f4Sint64; }
        if ($f5Bool !== null) { $this->f5Bool = $f5Bool; }
        if ($f6String !== null) { $this->f6String = $f6String; }
        if ($f7Bytes !== null) { $this->f7Bytes = $f7Bytes; }
        if ($f8SubMsg !== null) { $this->f8SubMsg = $f8SubMsg; }
        if ($f9StringArr !== null) { $this->f9StringArr = $f9StringArr; }
        if ($f10Uint32Arr !== null) { $this->f10Uint32Arr = $f10Uint32Arr; }
        if ($f11SubMsgArr !== null) { $this->f11SubMsgArr = $f11SubMsgArr; }
        if ($f12MapStrUint !== null) { $this->f12MapStrUint = $f12MapStrUint; }
        if ($f13Float32 !== null) { $this->f13Float32 = $f13Float32; }
        if ($f14Float64 !== null) { $this->f14Float64 = $f14Float64; }
        if ($f15Fixed32 !== null) { $this->f15Fixed32 = $f15Fixed32; }
        if ($f16Fixed64 !== null) { $this->f16Fixed64 = $f16Fixed64; }
        if ($f20OverflowStr !== null) { $this->f20OverflowStr = $f20OverflowStr; }
    }

    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string
    {
        $enc = new Encoder();
        if ($selfDescribing) {
            self::schemaMeta()->writeTo($enc, true);
        }
        $this->marshalTo($enc, $deterministic);
        return $enc->finish();
    }

    public function marshalTo(Encoder $enc, bool $deterministic = false): void
    {
        if ($this->f1Uint32 !== 0) {
            $enc->buf .= "\x21";
            $enc->writeVarint($this->f1Uint32 & 0xFFFFFFFF);
        }
        if ($this->f2Uint64 !== 0) {
            $enc->buf .= "\x41";
            $enc->writeVarint($this->f2Uint64);
        }
        if ($this->f3Sint32 !== 0) {
            $enc->buf .= "\x63";
            $n = ($this->f3Sint32 << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->f4Sint64 !== 0) {
            $enc->buf .= "\x83";
            $enc->writeVarint(($this->f4Sint64 << 1) ^ ($this->f4Sint64 >> 63));
        }
        if ($this->f5Bool) {
            $enc->buf .= "\xa7";
        }
        if ($this->f6String !== '') {
            $enc->buf .= "\xd5";
            $enc->writeVarint(\strlen($this->f6String));
            $enc->buf .= $this->f6String;
        }
        if ($this->f7Bytes !== '') {
            $enc->buf .= "\xf7";
            $enc->writeVarint(\strlen($this->f7Bytes));
            $enc->buf .= $this->f7Bytes;
        }
        if ($this->f8SubMsg !== null) {
            $enc->buf .= "\x32\x02";
            $enc->beginContainer();
            $this->f8SubMsg->marshalTo($enc, $deterministic);
            $enc->endContainer();
        }
        if ($this->f9StringArr !== []) {
            $enc->buf .= "\x76\x02";
            $enc->beginContainer();
            $enc->buf .= "\x0a";
            $enc->writeVarint(\count($this->f9StringArr));
            foreach ($this->f9StringArr as $item) {
                $enc->writeVarint(\strlen($item)); $enc->buf .= $item;
            }
            $enc->endContainer();
        }
        if ($this->f10Uint32Arr !== []) {
            $enc->buf .= "\xb6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x00";
            $enc->writeVarint(\count($this->f10Uint32Arr));
            foreach ($this->f10Uint32Arr as $item) {
                $enc->writeVarint($item & 0xFFFFFFFF);
            }
            $enc->endContainer();
        }
        if ($this->f11SubMsgArr !== []) {
            $enc->buf .= "\xf6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x0c";
            $enc->writeVarint(\count($this->f11SubMsgArr));
            foreach ($this->f11SubMsgArr as $item) {
                $enc->beginContainer(); $item->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
            }
            $enc->endContainer();
        }
        if ($this->f12MapStrUint !== []) {
            $entries = $this->f12MapStrUint;
            if ($deterministic) {
                uksort($entries, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
            }
            $enc->buf .= "\x3a\x03";
            $enc->beginContainer();
            $enc->buf .= "\x0a\x00";
            $enc->writeVarint(\count($entries));
            foreach ($entries as $k => $v) {
                $enc->writeVarint(\strlen($k)); $enc->buf .= $k;
                $enc->writeVarint($v);
            }
            $enc->endContainer();
        }
        if ($this->f13Float32 !== 0.0 || pack('g', $this->f13Float32) !== "\x00\x00\x00\x00") {
            $enc->buf .= "\x62\x03" . pack('g', $this->f13Float32);
        }
        if ($this->f14Float64 !== 0.0 || pack('e', $this->f14Float64) !== "\x00\x00\x00\x00\x00\x00\x00\x00") {
            $enc->buf .= "\xa6\x03" . pack('e', $this->f14Float64);
        }
        if ($this->f15Fixed32 !== 0) {
            $enc->buf .= "\xd2\x03" . pack('V', $this->f15Fixed32 & 0xFFFFFFFF);
        }
        if ($this->f16Fixed64 !== 0) {
            $enc->buf .= "\x16\x04" . pack('P', $this->f16Fixed64);
        }
        if ($this->f20OverflowStr !== '') {
            $enc->buf .= "\x2a\x05";
            $enc->writeVarint(\strlen($this->f20OverflowStr));
            $enc->buf .= $this->f20OverflowStr;
        }
    }

    public static function unmarshalOmnibuf(
        string $data,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): static {
        $msg = new self();
        $msg->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), false);
        return $msg;
    }

    public function unmarshalInto(
        string $data,
        bool $merge = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): void {
        $this->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), $merge);
    }

    public function decodeFrom(Decoder $dec, bool $merge = false): void
    {
        if (!$merge) {
            $this->f1Uint32 = 0;
            $this->f2Uint64 = 0;
            $this->f3Sint32 = 0;
            $this->f4Sint64 = 0;
            $this->f5Bool = false;
            $this->f6String = '';
            $this->f7Bytes = '';
            $this->f8SubMsg = null;
            $this->f9StringArr = [];
            $this->f10Uint32Arr = [];
            $this->f11SubMsgArr = [];
            $this->f12MapStrUint = [];
            $this->f13Float32 = 0.0;
            $this->f14Float64 = 0.0;
            $this->f15Fixed32 = 0;
            $this->f16Fixed64 = 0;
            $this->f20OverflowStr = '';
        }
        $buf = $dec->buf;
        $end = $dec->end;
        while ($dec->pos < $end) {
            $b0 = \ord($buf[$dec->pos]);
            if (($b0 & 1) !== 0) {
                $tag = $b0 >> 1;
                $dec->pos++;
                $fieldNum = $tag >> 4;
                $wireType = $tag & 0x0F;
                if ($fieldNum === 0 && $wireType !== WireType::SUB_MSG) {
                    throw new \OmniBuf\Exception\DecodeException("omnibuf: field #0 must use WireSubMsg");
                }
            } else {
                [$fieldNum, $wireType] = $dec->readTag();
            }
            if ($fieldNum === 0) {
                $dec->readField0();
                continue;
            }
            switch ($fieldNum) {
                case 1:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->f1Uint32 = $dec->readUint32();
                    break;
                case 2:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->f2Uint64 = $dec->readUint64();
                    break;
                case 3:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->f3Sint32 = $dec->readSint32();
                    break;
                case 4:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->f4Sint64 = $dec->readSint64();
                    break;
                case 5:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->f5Bool = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->f5Bool = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 6:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->f6String = $dec->readString();
                    break;
                case 7:
                    if ($wireType !== 11) { $dec->skipUnknown($wireType); break; }
                    $this->f7Bytes = $dec->readBytes();
                    break;
                case 8:
                    if ($wireType !== 12) { $dec->skipUnknown($wireType); break; }
                    $subDec = $dec->readSubMessageDecoder();
                    if ($this->f8SubMsg === null) {
                        $this->f8SubMsg = new \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg();
                        $this->f8SubMsg->decodeFrom($subDec, false);
                    } else {
                        $this->f8SubMsg->decodeFrom($subDec, true);
                    }
                    break;
                case 9:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(10);
                    for ($i = 0; $i < $count; $i++) {
                        $this->f9StringArr[] = $arrDec->readString();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 10:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(0);
                    for ($i = 0; $i < $count; $i++) {
                        $this->f10Uint32Arr[] = $arrDec->readUint32();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 11:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(12);
                    for ($i = 0; $i < $count; $i++) {
                        $this->f11SubMsgArr[] = (static function () use ($arrDec): \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg { $c = new \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg(); $c->decodeFrom($arrDec->readSubMessageDecoder(), false); return $c; })();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 12:
                    if ($wireType !== WireType::MAP) { $dec->skipUnknown($wireType); break; }
                    [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(10, 0);
                    for ($i = 0; $i < $count; $i++) {
                        $k = $mapDec->readString();
                        $this->f12MapStrUint[$k] = $mapDec->readUint64();
                    }
                    $mapDec->assertAtEnd('WireMap');
                    break;
                case 13:
                    if ($wireType !== 8) { $dec->skipUnknown($wireType); break; }
                    $this->f13Float32 = $dec->readFloat32();
                    break;
                case 14:
                    if ($wireType !== 9) { $dec->skipUnknown($wireType); break; }
                    $this->f14Float64 = $dec->readFloat64();
                    break;
                case 15:
                    if ($wireType !== 4) { $dec->skipUnknown($wireType); break; }
                    $this->f15Fixed32 = $dec->readFixed32();
                    break;
                case 16:
                    if ($wireType !== 5) { $dec->skipUnknown($wireType); break; }
                    $this->f16Fixed64 = $dec->readFixed64();
                    break;
                case 20:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->f20OverflowStr = $dec->readString();
                    break;
                default:
                    $dec->skipUnknown($wireType);
                    break;
            }
        }
    }

    public static function schemaMeta(): SchemaMeta
    {
        return new SchemaMeta(
            'omnibuf.conformance.TestAllTypes',
            [
                1 => 'f1_uint32',
                2 => 'f2_uint64',
                3 => 'f3_sint32',
                4 => 'f4_sint64',
                5 => 'f5_bool',
                6 => 'f6_string',
                7 => 'f7_bytes',
                8 => 'f8_sub_msg',
                9 => 'f9_string_arr',
                10 => 'f10_uint32_arr',
                11 => 'f11_sub_msg_arr',
                12 => 'f12_map_str_uint',
                13 => 'f13_float32',
                14 => 'f14_float64',
                15 => 'f15_fixed32',
                16 => 'f16_fixed64',
                20 => 'f20_overflow_str',
            ],
            [
                8 => \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg::schemaMeta(),
                11 => \OmniBuf\Generated\Omnibuf\Conformance\AllTypesSubMsg::schemaMeta(),
            ],
        );
    }
}
