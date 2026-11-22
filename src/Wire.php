<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;

/**
 * Low-level OmniBuf v0.1 wire primitives: Prefix-Varint, ZigZag, tags, validation, skipping, and splicing.
 */
final class Wire
{
    public const int MAX_FIELD_NUMBER = 536_870_911;
    public const int FIELD0_TAG = 0x19;
    public const int MAX_NESTING_DEPTH = 32;
    public const int DEFAULT_MAX_ELEMENTS = 1_048_576;

    /**
     * Encodes an unsigned 64-bit integer (raw 64-bit bit pattern) as a 1-, 2-, 4-, or 9-byte Prefix-Varint.
     */
    public static function encodeVarint(int $u): string
    {
        if ($u >= 0) {
            if ($u < 128) {
                return \chr(($u << 1) | 1);
            }
            if ($u < 16_384) {
                return pack('v', ($u << 2) | 2);
            }
            if ($u < 536_870_912) {
                return pack('V', ($u << 3) | 4);
            }
        }
        return "\x00" . pack('P', $u);
    }

    /**
     * Returns the minimal encoded byte width (1, 2, 4, or 9) of a Prefix-Varint.
     */
    public static function varintSize(int $u): int
    {
        if ($u >= 0) {
            if ($u < 128) {
                return 1;
            }
            if ($u < 16_384) {
                return 2;
            }
            if ($u < 536_870_912) {
                return 4;
            }
        }
        return 9;
    }

    /**
     * Decodes a Prefix-Varint at `$pos` within `$buf[0..$end]` and advances `$pos`.
     * Returns the raw 64-bit unsigned integer bit pattern as a PHP `int`.
     */
    public static function decodeVarint(string $buf, int &$pos, int $end): int
    {
        if ($pos >= $end) {
            throw new DecodeException("omnibuf: unexpected EOF reading prefix-varint at offset {$pos}");
        }
        $b0 = \ord($buf[$pos]);
        if (($b0 & 1) !== 0) {
            $pos++;
            return $b0 >> 1;
        }
        if (($b0 & 2) !== 0) {
            if ($end - $pos < 2) {
                throw new DecodeException("omnibuf: truncated 2-byte prefix-varint at offset {$pos}");
            }
            $val = ($b0 | (\ord($buf[$pos + 1]) << 8)) >> 2;
            $pos += 2;
            return $val;
        }
        if (($b0 & 4) !== 0) {
            if ($end - $pos < 4) {
                throw new DecodeException("omnibuf: truncated 4-byte prefix-varint at offset {$pos}");
            }
            $val = ($b0
                | (\ord($buf[$pos + 1]) << 8)
                | (\ord($buf[$pos + 2]) << 16)
                | (\ord($buf[$pos + 3]) << 24)) >> 3;
            $pos += 4;
            return $val;
        }
        if ($b0 === 0) {
            if ($end - $pos < 9) {
                throw new DecodeException("omnibuf: truncated 9-byte prefix-varint at offset {$pos}");
            }
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('P', $buf, $pos + 1);
            $pos += 9;
            return $unpacked[1];
        }
        throw new DecodeException(\sprintf('omnibuf: reserved prefix-varint marker 0x%02x at offset %d', $b0, $pos));
    }

    /**
     * Encodes a field tag `(fieldNum << 4) | wireType`.
     */
    public static function encodeTag(int $fieldNum, int $wireType): string
    {
        if ($fieldNum < 0 || $fieldNum > self::MAX_FIELD_NUMBER) {
            throw new EncodeException("omnibuf: field number {$fieldNum} out of range [0.." . self::MAX_FIELD_NUMBER . ']');
        }
        if ($wireType < 0 || $wireType > 15) {
            throw new EncodeException("omnibuf: invalid wire type {$wireType}");
        }
        return self::encodeVarint(($fieldNum << 4) | $wireType);
    }

    /**
     * Decodes a field tag at `$pos` and returns `[fieldNum, wireType]`.
     *
     * @return array{0: int, 1: int}
     */
    public static function decodeTag(string $buf, int &$pos, int $end): array
    {
        $start = $pos;
        $tag = self::decodeVarint($buf, $pos, $end);
        if ($tag < 0) {
            throw new DecodeException("omnibuf: tag overflow at offset {$start}");
        }
        $fieldNum = $tag >> 4;
        if ($fieldNum > self::MAX_FIELD_NUMBER) {
            throw new DecodeException("omnibuf: field number {$fieldNum} exceeds max " . self::MAX_FIELD_NUMBER . " at offset {$start}");
        }
        $wireType = $tag & 0x0F;
        if ($fieldNum === 0 && $wireType !== WireType::SUB_MSG) {
            throw new DecodeException("omnibuf: field #0 must use WireSubMsg (12), got {$wireType} at offset {$start}");
        }
        return [$fieldNum, $wireType];
    }

    /**
     * ZigZag-encodes a signed 64-bit integer into a raw 64-bit unsigned bit pattern.
     */
    public static function encodeZigZag(int $n): int
    {
        return ($n << 1) ^ ($n >> 63);
    }

    /**
     * ZigZag-decodes a raw 64-bit unsigned bit pattern into a signed 64-bit integer.
     */
    public static function decodeZigZag(int $u): int
    {
        return (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
    }

    /**
     * Narrows a 64-bit signed integer to two's-complement 32-bit signed integer range.
     */
    public static function narrowInt32(int $n): int
    {
        return ($n << 32) >> 32;
    }

    /**
     * Narrows a 64-bit unsigned integer to 32-bit unsigned integer range.
     */
    public static function narrowUint32(int $u): int
    {
        return $u & 0xFFFFFFFF;
    }

    /**
     * Strict UTF-8 validation (rejects overlong encodings, UTF-16 surrogates, and > U+10FFFF).
     */
    public static function isValidUtf8(string $bytes): bool
    {
        if ($bytes === '') {
            return true;
        }
        return preg_match('//u', $bytes) === 1;
    }

    /**
     * Rounds a float32 value to its shortest round-tripping decimal float64 representation.
     */
    public static function roundFloat32Shortest(float $f): float
    {
        if (!is_finite($f) || $f === 0.0) {
            return $f;
        }
        $target = pack('g', $f);
        for ($prec = 1; $prec <= 9; $prec++) {
            $candidate = (float) \sprintf("%.{$prec}g", $f);
            if (pack('g', $candidate) === $target) {
                return $candidate;
            }
        }
        return $f;
    }

    /**
     * Reads a length-prefixed payload range `[bodyStart, bodyEnd]` and advances `$pos` past it.
     *
     * @return array{0: int, 1: int}
     */
    public static function readLenRange(string $buf, int &$pos, int $end, int $minLen = 0): array
    {
        $lenPos = $pos;
        $byteLen = self::decodeVarint($buf, $pos, $end);
        if ($byteLen < $minLen) {
            throw new DecodeException("omnibuf: length {$byteLen} is below minimum {$minLen} at offset {$lenPos}");
        }
        if ($byteLen > $end - $pos) {
            throw new DecodeException("omnibuf: length {$byteLen} exceeds remaining " . ($end - $pos) . " bytes at offset {$lenPos}");
        }
        $bodyStart = $pos;
        $bodyEnd = $pos + $byteLen;
        $pos = $bodyEnd;
        return [$bodyStart, $bodyEnd];
    }

    /**
     * O(1) skips a single field value of `$wireType` starting at `$pos` within `$buf[0..$end]`.
     */
    public static function fastSkipValue(string $buf, int &$pos, int $end, int $wireType): void
    {
        switch ($wireType) {
            case WireType::BOOL_FALSE:
            case WireType::BOOL_TRUE:
                return;
            case WireType::UINT:
            case WireType::SINT:
                self::decodeVarint($buf, $pos, $end);
                return;
            case WireType::FIXED32:
            case WireType::SFIXED32:
            case WireType::FLOAT32:
                if ($end - $pos < 4) {
                    throw new DecodeException("omnibuf: truncated 4-byte value at offset {$pos}");
                }
                $pos += 4;
                return;
            case WireType::FIXED64:
            case WireType::SFIXED64:
            case WireType::FLOAT64:
                if ($end - $pos < 8) {
                    throw new DecodeException("omnibuf: truncated 8-byte value at offset {$pos}");
                }
                $pos += 8;
                return;
            case WireType::STRING:
            case WireType::BYTES:
            case WireType::SUB_MSG:
                self::readLenRange($buf, $pos, $end, 0);
                return;
            case WireType::ARRAY:
                self::readLenRange($buf, $pos, $end, 2);
                return;
            case WireType::MAP:
                self::readLenRange($buf, $pos, $end, 3);
                return;
            case WireType::EXTENDED:
                self::readLenRange($buf, $pos, $end, 1);
                return;
            default:
                throw new DecodeException("omnibuf: invalid wire type {$wireType} at offset {$pos}");
        }
    }

    /**
     * Recursively validates a field value at `$pos` within `$buf[0..$end]` and advances `$pos`.
     */
    public static function validateValue(
        string $buf,
        int &$pos,
        int $end,
        int $wireType,
        int $depth = 0,
        int $maxDepth = self::MAX_NESTING_DEPTH,
        int $maxElements = self::DEFAULT_MAX_ELEMENTS,
        bool $validateUtf8 = false,
    ): void {
        switch ($wireType) {
            case WireType::BOOL_FALSE:
            case WireType::BOOL_TRUE:
                return;
            case WireType::UINT:
            case WireType::SINT:
                self::decodeVarint($buf, $pos, $end);
                return;
            case WireType::FIXED32:
            case WireType::SFIXED32:
            case WireType::FLOAT32:
                if ($end - $pos < 4) {
                    throw new DecodeException("omnibuf: truncated 4-byte value at offset {$pos}");
                }
                $pos += 4;
                return;
            case WireType::FIXED64:
            case WireType::SFIXED64:
            case WireType::FLOAT64:
                if ($end - $pos < 8) {
                    throw new DecodeException("omnibuf: truncated 8-byte value at offset {$pos}");
                }
                $pos += 8;
                return;
            case WireType::STRING:
                [$bStart, $bEnd] = self::readLenRange($buf, $pos, $end, 0);
                if ($validateUtf8 && $bEnd > $bStart && !self::isValidUtf8(substr($buf, $bStart, $bEnd - $bStart))) {
                    throw new DecodeException("omnibuf: invalid UTF-8 string at offset {$bStart}");
                }
                return;
            case WireType::BYTES:
                self::readLenRange($buf, $pos, $end, 0);
                return;
            case WireType::SUB_MSG:
                [$bStart, $bEnd] = self::readLenRange($buf, $pos, $end, 0);
                self::validateMessage($buf, $bStart, $bEnd, $depth + 1, $maxDepth, $maxElements, $validateUtf8);
                return;
            case WireType::ARRAY:
                [$bStart, $bEnd] = self::readLenRange($buf, $pos, $end, 2);
                self::validateArrayBody($buf, $bStart, $bEnd, $depth + 1, $maxDepth, $maxElements, $validateUtf8);
                return;
            case WireType::MAP:
                [$bStart, $bEnd] = self::readLenRange($buf, $pos, $end, 3);
                self::validateMapBody($buf, $bStart, $bEnd, $depth + 1, $maxDepth, $maxElements, $validateUtf8);
                return;
            case WireType::EXTENDED:
                self::readLenRange($buf, $pos, $end, 1);
                return;
            default:
                throw new DecodeException("omnibuf: invalid wire type {$wireType} at offset {$pos}");
        }
    }

    /**
     * Validates a complete message field stream `$buf[$start..$end]`.
     */
    public static function validateMessage(
        string $buf,
        int $start,
        int $end,
        int $depth = 0,
        int $maxDepth = self::MAX_NESTING_DEPTH,
        int $maxElements = self::DEFAULT_MAX_ELEMENTS,
        bool $validateUtf8 = false,
    ): void {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        $pos = $start;
        while ($pos < $end) {
            [$fieldNum, $wireType] = self::decodeTag($buf, $pos, $end);
            if ($fieldNum === 0) {
                [$bStart, $bEnd] = self::readLenRange($buf, $pos, $end, 0);
                SchemaMeta::decodeRange($buf, $bStart, $bEnd, $depth + 1, $maxDepth, $maxElements);
                continue;
            }
            self::validateValue($buf, $pos, $end, $wireType, $depth, $maxDepth, $maxElements, $validateUtf8);
        }
    }

    public static function validateArrayBody(
        string $buf,
        int $start,
        int $end,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $validateUtf8,
    ): void {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: array nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        if ($end - $start < 2) {
            throw new DecodeException("omnibuf: truncated WireArray header at offset {$start}");
        }
        $elemType = \ord($buf[$start]);
        if (!WireType::isValidCollectionType($elemType)) {
            throw new DecodeException("omnibuf: invalid WireArray element type {$elemType} at offset {$start}");
        }
        $pos = $start + 1;
        $count = self::decodeVarint($buf, $pos, $end);
        if ($count < 0) {
            throw new DecodeException("omnibuf: WireArray count overflow at offset {$start}");
        }
        $rem = $end - $pos;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireArray with count=0 has {$rem} trailing bytes at offset {$pos}");
            }
            return;
        }
        $fixedWidth = WireType::collectionFixedWidth($elemType);
        if ($fixedWidth > 0) {
            if ($rem % $fixedWidth !== 0 || $count !== intdiv($rem, $fixedWidth)) {
                throw new DecodeException("omnibuf: WireArray fixed-width count {$count} does not match remaining {$rem} bytes");
            }
            if ($elemType === WireType::BOOL_FALSE) {
                for ($i = $pos; $i < $end; $i++) {
                    $b = \ord($buf[$i]);
                    if ($b !== 0 && $b !== 1) {
                        throw new DecodeException("omnibuf: invalid WireArray bool byte {$b} at offset {$i}");
                    }
                }
            }
            return;
        }
        if ($count > $rem) {
            throw new DecodeException("omnibuf: WireArray count {$count} exceeds remaining {$rem} bytes");
        }
        if ($count > $maxElements) {
            throw new DecodeException("omnibuf: WireArray count {$count} exceeds maxElements {$maxElements}");
        }
        for ($i = 0; $i < $count; $i++) {
            self::validateCollectionItem($buf, $pos, $end, $elemType, $depth, $maxDepth, $maxElements, $validateUtf8);
        }
        if ($pos !== $end) {
            throw new DecodeException('omnibuf: WireArray has ' . ($end - $pos) . " trailing bytes at offset {$pos}");
        }
    }

    public static function validateMapBody(
        string $buf,
        int $start,
        int $end,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $validateUtf8,
    ): void {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: map nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        if ($end - $start < 3) {
            throw new DecodeException("omnibuf: truncated WireMap header at offset {$start}");
        }
        $keyType = \ord($buf[$start]);
        $valType = \ord($buf[$start + 1]);
        if (!WireType::isValidKeyType($keyType)) {
            throw new DecodeException("omnibuf: invalid WireMap key type {$keyType} at offset {$start}");
        }
        if (!WireType::isValidCollectionType($valType)) {
            throw new DecodeException("omnibuf: invalid WireMap value type {$valType} at offset " . ($start + 1));
        }
        $pos = $start + 2;
        $count = self::decodeVarint($buf, $pos, $end);
        if ($count < 0) {
            throw new DecodeException("omnibuf: WireMap count overflow at offset {$start}");
        }
        $rem = $end - $pos;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireMap with count=0 has {$rem} trailing bytes at offset {$pos}");
            }
            return;
        }
        if ($count > intdiv($rem, 2)) {
            throw new DecodeException("omnibuf: WireMap count {$count} exceeds remaining {$rem} bytes / 2");
        }
        if ($count > $maxElements) {
            throw new DecodeException("omnibuf: WireMap count {$count} exceeds maxElements {$maxElements}");
        }
        for ($i = 0; $i < $count; $i++) {
            self::validateCollectionItem($buf, $pos, $end, $keyType, $depth, $maxDepth, $maxElements, $validateUtf8);
            self::validateCollectionItem($buf, $pos, $end, $valType, $depth, $maxDepth, $maxElements, $validateUtf8);
        }
        if ($pos !== $end) {
            throw new DecodeException('omnibuf: WireMap has ' . ($end - $pos) . " trailing bytes at offset {$pos}");
        }
    }

    private static function validateCollectionItem(
        string $buf,
        int &$pos,
        int $end,
        int $wireType,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $validateUtf8,
    ): void {
        if ($wireType === WireType::BOOL_FALSE) {
            if ($pos >= $end) {
                throw new DecodeException("omnibuf: truncated collection bool at offset {$pos}");
            }
            $b = \ord($buf[$pos++]);
            if ($b !== 0 && $b !== 1) {
                throw new DecodeException("omnibuf: invalid collection bool byte {$b}");
            }
            return;
        }
        self::validateValue($buf, $pos, $end, $wireType, $depth, $maxDepth, $maxElements, $validateUtf8);
    }

    /**
     * In-place wire splicing: replaces/removes specified fields in `$packet` without decoding
     * untouched fields, preserving `$prefix` verbatim and never mutating `$packet`.
     *
     * @param array<int, string> $replacements Map of fieldNumber => encoded field bytes (empty string to delete).
     */
    public static function spliceFields(string $prefix, string $packet, array $replacements): string
    {
        $end = \strlen($packet);
        if ($replacements === []) {
            // Still validate packet framing
            $pos = 0;
            while ($pos < $end) {
                [$fieldNum, $wt] = self::decodeTag($packet, $pos, $end);
                if ($fieldNum === 0) {
                    [$bStart, $bEnd] = self::readLenRange($packet, $pos, $end, 0);
                    SchemaMeta::decodeRange($packet, $bStart, $bEnd, 1, self::MAX_NESTING_DEPTH, self::DEFAULT_MAX_ELEMENTS);
                } else {
                    self::fastSkipValue($packet, $pos, $end, $wt);
                }
            }
            return $prefix . $packet;
        }

        ksort($replacements, SORT_NUMERIC);
        $emitted = [];
        $out = $prefix;
        $pos = 0;
        $copyStart = 0;

        while ($pos < $end) {
            $fieldStart = $pos;
            [$fieldNum, $wt] = self::decodeTag($packet, $pos, $end);
            if ($fieldNum === 0) {
                [$bStart, $bEnd] = self::readLenRange($packet, $pos, $end, 0);
                SchemaMeta::decodeRange($packet, $bStart, $bEnd, 1, self::MAX_NESTING_DEPTH, self::DEFAULT_MAX_ELEMENTS);
                continue;
            }
            self::fastSkipValue($packet, $pos, $end, $wt);

            if (\array_key_exists($fieldNum, $replacements)) {
                if ($fieldStart > $copyStart) {
                    $out .= substr($packet, $copyStart, $fieldStart - $copyStart);
                }
                if (!isset($emitted[$fieldNum])) {
                    $out .= $replacements[$fieldNum];
                    $emitted[$fieldNum] = true;
                }
                $copyStart = $pos;
            }
        }

        if ($end > $copyStart) {
            $out .= substr($packet, $copyStart, $end - $copyStart);
        }

        foreach ($replacements as $fieldNum => $encoded) {
            if (!isset($emitted[$fieldNum]) && $encoded !== '') {
                $out .= $encoded;
            }
        }

        return $out;
    }
}
