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

final class AllTypesSubMsg implements GeneratedMessage
{
    public int $id = 0;

    public string $label = '';

    public bool $flag = false;

    public int $score = 0;

    public function __construct(
        ?int $id = null,
        ?string $label = null,
        ?bool $flag = null,
        ?int $score = null,
    ) {
        if ($id !== null) { $this->id = $id; }
        if ($label !== null) { $this->label = $label; }
        if ($flag !== null) { $this->flag = $flag; }
        if ($score !== null) { $this->score = $score; }
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
        if ($this->id !== 0) {
            $enc->buf .= "\x21";
            $enc->writeVarint($this->id & 0xFFFFFFFF);
        }
        if ($this->label !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->label));
            $enc->buf .= $this->label;
        }
        if ($this->flag) {
            $enc->buf .= "\x67";
        }
        if ($this->score !== 0) {
            $enc->buf .= "\x83";
            $enc->writeVarint(($this->score << 1) ^ ($this->score >> 63));
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
            $this->id = 0;
            $this->label = '';
            $this->flag = false;
            $this->score = 0;
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
                    $this->id = $dec->readUint32();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->label = $dec->readString();
                    break;
                case 3:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->flag = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->flag = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 4:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->score = $dec->readSint64();
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
            'omnibuf.conformance.AllTypesSubMsg',
            [
                1 => 'id',
                2 => 'label',
                3 => 'flag',
                4 => 'score',
            ],
            [
            ],
        );
    }
}
