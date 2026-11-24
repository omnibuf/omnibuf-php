<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\EncodeException;

/**
 * Single-pass forward OmniBuf v1 DRAFT-1 wire encoder with 2-byte length backpatching.
 */
final class Encoder
{
    public string $buf = '';

    /** @var list<int> */
    private array $containers = [];

    public function finish(): string
    {
        if ($this->containers !== []) {
            throw new EncodeException('omnibuf: unclosed container(s) in encoder');
        }
        return $this->buf;
    }

    public function reset(): void
    {
        $this->buf = '';
        $this->containers = [];
    }

    public function writeByte(int $b): void
    {
        $this->buf .= \chr($b & 0xFF);
    }

    public function writeRaw(string $bytes): void
    {
        $this->buf .= $bytes;
    }

    public function writeVarint(int $u): void
    {
        if ($u >= 0) {
            if ($u < 128) {
                $this->buf .= \chr(($u << 1) | 1);
                return;
            }
            if ($u < 16_384) {
                $this->buf .= pack('v', ($u << 2) | 2);
                return;
            }
            if ($u < 536_870_912) {
                $this->buf .= pack('V', ($u << 3) | 4);
                return;
            }
        }
        $this->buf .= "\x00" . pack('P', $u);
    }

    public function writeTag(int $fieldNum, int $wireType): void
    {
        if ($fieldNum < 0 || $fieldNum > Wire::MAX_FIELD_NUMBER) {
            throw new EncodeException("omnibuf: field number {$fieldNum} out of range [0.." . Wire::MAX_FIELD_NUMBER . ']');
        }
        if ($wireType < 0 || $wireType > 15) {
            throw new EncodeException("omnibuf: invalid wire type {$wireType}");
        }
        if ($fieldNum === 0 && $wireType !== WireType::SUB_MSG) {
            throw new EncodeException("omnibuf: field #0 must use WireSubMsg (12), got {$wireType}");
        }
        $tag = ($fieldNum << 4) | $wireType;
        if ($tag < 128) {
            $this->buf .= \chr(($tag << 1) | 1);
            return;
        }
        if ($tag < 16_384) {
            $this->buf .= pack('v', ($tag << 2) | 2);
            return;
        }
        if ($tag < 536_870_912) {
            $this->buf .= pack('V', ($tag << 3) | 4);
            return;
        }
        $this->buf .= "\x00" . pack('P', $tag);
    }

    public function beginContainer(): void
    {
        if (\count($this->containers) >= Wire::MAX_NESTING_DEPTH) {
            throw new EncodeException('omnibuf: container nesting depth exceeds maximum ' . Wire::MAX_NESTING_DEPTH);
        }
        $this->containers[] = \strlen($this->buf);
        $this->buf .= "\x00\x00";
    }

    public function endContainer(): void
    {
        $lenPos = array_pop($this->containers);
        if ($lenPos === null) {
            throw new EncodeException('omnibuf: endContainer called without matching beginContainer');
        }
        $byteLen = \strlen($this->buf) - ($lenPos + 2);
        if ($byteLen < 16_384) {
            $v = ($byteLen << 2) | 2;
            $this->buf[$lenPos] = \chr($v & 0xFF);
            $this->buf[$lenPos + 1] = \chr(($v >> 8) & 0xFF);
            return;
        }
        $header = Wire::encodeVarint($byteLen);
        $this->buf = substr_replace($this->buf, $header, $lenPos, 2);
    }

    public function writeUintField(int $fieldNum, int|string $val): void
    {
        $this->writeTag($fieldNum, WireType::UINT);
        $this->writeVarint(\is_int($val) ? $val : UInt64::fromDecimalString($val));
    }

    public function writeUint32Field(int $fieldNum, int $val): void
    {
        $this->writeTag($fieldNum, WireType::UINT);
        $this->writeVarint($val & 0xFFFFFFFF);
    }

    public function writeSintField(int $fieldNum, int $val): void
    {
        $this->writeTag($fieldNum, WireType::SINT);
        $this->writeVarint(($val << 1) ^ ($val >> 63));
    }

    public function writeSint32Field(int $fieldNum, int $val): void
    {
        $n = ($val << 32) >> 32;
        $this->writeTag($fieldNum, WireType::SINT);
        $this->writeVarint(($n << 1) ^ ($n >> 63));
    }

    public function writeBoolField(int $fieldNum, bool $val): void
    {
        $this->writeTag($fieldNum, $val ? WireType::BOOL_TRUE : WireType::BOOL_FALSE);
    }

    public function writeFixed32Field(int $fieldNum, int $val): void
    {
        $this->writeTag($fieldNum, WireType::FIXED32);
        $this->buf .= pack('V', $val & 0xFFFFFFFF);
    }

    public function writeFixed64Field(int $fieldNum, int|string $val): void
    {
        $this->writeTag($fieldNum, WireType::FIXED64);
        $this->buf .= pack('P', \is_int($val) ? $val : UInt64::fromDecimalString($val));
    }

    public function writeSfixed32Field(int $fieldNum, int $val): void
    {
        $this->writeTag($fieldNum, WireType::SFIXED32);
        $this->buf .= pack('V', $val & 0xFFFFFFFF);
    }

    public function writeSfixed64Field(int $fieldNum, int $val): void
    {
        $this->writeTag($fieldNum, WireType::SFIXED64);
        $this->buf .= pack('P', $val);
    }

    public function writeFloat32Field(int $fieldNum, float $val): void
    {
        $this->writeTag($fieldNum, WireType::FLOAT32);
        $this->buf .= pack('g', $val);
    }

    public function writeFloat64Field(int $fieldNum, float $val): void
    {
        $this->writeTag($fieldNum, WireType::FLOAT64);
        $this->buf .= pack('e', $val);
    }

    public function writeStringField(int $fieldNum, string $val): void
    {
        if ($val !== '' && !Wire::isValidUtf8($val)) {
            throw new EncodeException("omnibuf: invalid UTF-8 string in field #{$fieldNum}");
        }
        $this->writeTag($fieldNum, WireType::STRING);
        $this->writeVarint(\strlen($val));
        $this->buf .= $val;
    }

    public function writeBytesField(int $fieldNum, string $val): void
    {
        $this->writeTag($fieldNum, WireType::BYTES);
        $this->writeVarint(\strlen($val));
        $this->buf .= $val;
    }

    public function writeRawStringPayload(string $val): void
    {
        if ($val !== '' && !Wire::isValidUtf8($val)) {
            throw new EncodeException('omnibuf: invalid UTF-8 string payload');
        }
        $this->writeVarint(\strlen($val));
        $this->buf .= $val;
    }

    public function writeRawBytesPayload(string $val): void
    {
        $this->writeVarint(\strlen($val));
        $this->buf .= $val;
    }

    public function writeFixed32Raw(int $val): void
    {
        $this->buf .= pack('V', $val & 0xFFFFFFFF);
    }

    public function writeFixed64Raw(int|string $val): void
    {
        $this->buf .= pack('P', \is_int($val) ? $val : UInt64::fromDecimalString($val));
    }

    public function writeFloat32Raw(float $val): void
    {
        $this->buf .= pack('g', $val);
    }

    public function writeFloat64Raw(float $val): void
    {
        $this->buf .= pack('e', $val);
    }

    public function writeSintRaw(int $val): void
    {
        $this->writeVarint(($val << 1) ^ ($val >> 63));
    }
}
