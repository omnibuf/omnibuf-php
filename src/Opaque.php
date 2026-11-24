<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;

/**
 * Low-level O(fields) header-hop field lookup and lazy decoding helpers for Opaque<T> views.
 */
final class Opaque
{
    /**
     * Scans `$buf[$start..$end]` in O(fields) using O(1) field skips and returns the `[valueStart, valueEnd, wireType]`
     * of the LAST occurrence of `$targetField` matching `$expectedWireType` (or bool true/false when `$expectedWireType === WireType::BOOL_FALSE`).
     * Returns null if not found.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function locateLast(string $buf, int $start, int $end, int $targetField, int $expectedWireType): ?array
    {
        if (\function_exists('omnibuf_native_opaque_locate_last')) {
            return \omnibuf_native_opaque_locate_last($buf, $start, $end, $targetField, $expectedWireType);
        }
        $pos = $start;
        $found = null;
        $isBool = $expectedWireType === WireType::BOOL_FALSE || $expectedWireType === WireType::BOOL_TRUE;

        while ($pos < $end) {
            [$fieldNum, $wt] = Wire::decodeTag($buf, $pos, $end);
            $valStart = $pos;
            Wire::fastSkipValue($buf, $pos, $end, $wt);
            if ($fieldNum === $targetField) {
                if ($isBool) {
                    if ($wt === WireType::BOOL_FALSE || $wt === WireType::BOOL_TRUE) {
                        $found = [$valStart, $pos, $wt];
                    }
                } elseif ($wt === $expectedWireType) {
                    $found = [$valStart, $pos, $wt];
                }
            }
        }
        return $found;
    }

    /**
     * Scans `$buf[$start..$end]` and returns all `[bodyStart, bodyEnd]` payload ranges for a length-prefixed field.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function locateAllLengthPrefixed(string $buf, int $start, int $end, int $targetField, int $expectedWireType, int $minLen = 0): array
    {
        $pos = $start;
        $ranges = [];
        while ($pos < $end) {
            [$fieldNum, $wt] = Wire::decodeTag($buf, $pos, $end);
            if ($fieldNum === $targetField && $wt === $expectedWireType) {
                $ranges[] = Wire::readLenRange($buf, $pos, $end, $minLen);
                continue;
            }
            Wire::fastSkipValue($buf, $pos, $end, $wt);
        }
        return $ranges;
    }

    /**
     * Scans `$buf[$start..$end]` and returns all `[valStart, valEnd]` raw value ranges (including the length prefix)
     * for occurrences of `$targetField` matching `$expectedWireType`.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function locateAllRawRanges(string $buf, int $start, int $end, int $targetField, int $expectedWireType): array
    {
        $pos = $start;
        $ranges = [];
        while ($pos < $end) {
            [$fieldNum, $wt] = Wire::decodeTag($buf, $pos, $end);
            $valStart = $pos;
            Wire::fastSkipValue($buf, $pos, $end, $wt);
            if ($fieldNum === $targetField && $wt === $expectedWireType) {
                $ranges[] = [$valStart, $pos];
            }
        }
        return $ranges;
    }

    public static function getUint32(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::UINT);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        return Wire::decodeVarint($buf, $p, $hit[1]) & 0xFFFFFFFF;
    }

    public static function getUint64(string $buf, int $start, int $end, int $fieldNum): int
    {
        if (\function_exists('omnibuf_native_opaque_get_uint64')) {
            return \omnibuf_native_opaque_get_uint64($buf, $start, $end, $fieldNum);
        }
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::UINT);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        return Wire::decodeVarint($buf, $p, $hit[1]);
    }

    public static function getSint32(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::SINT);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        $u = Wire::decodeVarint($buf, $p, $hit[1]);
        $n = (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
        return ($n << 32) >> 32;
    }

    public static function getSint64(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::SINT);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        $u = Wire::decodeVarint($buf, $p, $hit[1]);
        return (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
    }

    public static function getBool(string $buf, int $start, int $end, int $fieldNum): bool
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::BOOL_FALSE);
        if ($hit === null) {
            return false;
        }
        return $hit[2] === WireType::BOOL_TRUE;
    }

    public static function getFixed32(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::FIXED32);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        return (\ord($buf[$p]) | (\ord($buf[$p + 1]) << 8) | (\ord($buf[$p + 2]) << 16) | (\ord($buf[$p + 3]) << 24)) & 0xFFFFFFFF;
    }

    public static function getSfixed32(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::SFIXED32);
        if ($hit === null) {
            return 0;
        }
        $p = $hit[0];
        $u = (\ord($buf[$p]) | (\ord($buf[$p + 1]) << 8) | (\ord($buf[$p + 2]) << 16) | (\ord($buf[$p + 3]) << 24)) & 0xFFFFFFFF;
        return ($u << 32) >> 32;
    }

    public static function getFixed64(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::FIXED64);
        if ($hit === null) {
            return 0;
        }
        /** @var array{1: int} $u */
        $u = unpack('P', $buf, $hit[0]);
        return $u[1];
    }

    public static function getSfixed64(string $buf, int $start, int $end, int $fieldNum): int
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::SFIXED64);
        if ($hit === null) {
            return 0;
        }
        /** @var array{1: int} $u */
        $u = unpack('P', $buf, $hit[0]);
        return $u[1];
    }

    public static function getFloat32(string $buf, int $start, int $end, int $fieldNum): float
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::FLOAT32);
        if ($hit === null) {
            return 0.0;
        }
        /** @var array{1: float} $u */
        $u = unpack('g', $buf, $hit[0]);
        return $u[1];
    }

    public static function getFloat64(string $buf, int $start, int $end, int $fieldNum): float
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::FLOAT64);
        if ($hit === null) {
            return 0.0;
        }
        /** @var array{1: float} $u */
        $u = unpack('e', $buf, $hit[0]);
        return $u[1];
    }

    public static function getString(string $buf, int $start, int $end, int $fieldNum): string
    {
        if (\function_exists('omnibuf_native_opaque_get_string')) {
            return \omnibuf_native_opaque_get_string($buf, $start, $end, $fieldNum);
        }
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::STRING);
        if ($hit === null) {
            return '';
        }
        $p = $hit[0];
        [$bStart, $bEnd] = Wire::readLenRange($buf, $p, $hit[1], 0);
        if ($bStart === $bEnd) {
            return '';
        }
        $s = substr($buf, $bStart, $bEnd - $bStart);
        if (!Wire::isValidUtf8($s)) {
            throw new DecodeException("omnibuf: invalid UTF-8 string in field #{$fieldNum}");
        }
        return $s;
    }

    public static function getBytes(string $buf, int $start, int $end, int $fieldNum): string
    {
        $hit = self::locateLast($buf, $start, $end, $fieldNum, WireType::BYTES);
        if ($hit === null) {
            return '';
        }
        $p = $hit[0];
        [$bStart, $bEnd] = Wire::readLenRange($buf, $p, $hit[1], 0);
        if ($bStart === $bEnd) {
            return '';
        }
        return substr($buf, $bStart, $bEnd - $bStart);
    }

    /**
     * Returns concatenated raw submessage payload bytes for all occurrences of `$fieldNum`, or null if absent.
     */
    public static function getSubMessageSlice(string $buf, int $start, int $end, int $fieldNum): ?string
    {
        if (\function_exists('omnibuf_native_opaque_get_submsg_slice')) {
            return \omnibuf_native_opaque_get_submsg_slice($buf, $start, $end, $fieldNum);
        }
        $ranges = self::locateAllLengthPrefixed($buf, $start, $end, $fieldNum, WireType::SUB_MSG, 0);
        if ($ranges === []) {
            return null;
        }
        if (\count($ranges) === 1) {
            [$s, $e] = $ranges[0];
            return substr($buf, $s, $e - $s);
        }
        $merged = '';
        foreach ($ranges as [$s, $e]) {
            $merged .= substr($buf, $s, $e - $s);
        }
        return $merged;
    }

    /**
     * @return list<string>
     */
    public static function getStringArray(
        string $buf,
        int $start,
        int $end,
        int $fieldNum,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): array {
        $ranges = self::locateAllRawRanges($buf, $start, $end, $fieldNum, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($buf, $s, $e, 0, Wire::MAX_NESTING_DEPTH, $maxElements);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(WireType::STRING);
            if (\count($out) + $count > $maxElements) {
                throw new DecodeException("omnibuf: WireArray count exceeds maxElements {$maxElements}");
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readString();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }

    /**
     * @return list<int>
     */
    public static function getUint32Array(
        string $buf,
        int $start,
        int $end,
        int $fieldNum,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): array {
        $ranges = self::locateAllRawRanges($buf, $start, $end, $fieldNum, WireType::ARRAY);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($buf, $s, $e, 0, Wire::MAX_NESTING_DEPTH, $maxElements);
            [$arrDec, $_et, $count] = $dec->readArrayHeader(WireType::UINT);
            if (\count($out) + $count > $maxElements) {
                throw new DecodeException("omnibuf: WireArray count exceeds maxElements {$maxElements}");
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = $arrDec->readUint32();
            }
            $arrDec->assertAtEnd('WireArray');
        }
        return $out;
    }
}
