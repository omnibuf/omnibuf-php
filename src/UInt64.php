<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\EncodeException;

/**
 * Exact unsigned 64-bit integer helpers for 64-bit PHP.
 *
 * Internally, unsigned 64-bit integers are carried as raw 64-bit two's-complement PHP `int`
 * bit patterns (`0..PHP_INT_MAX` for `0..2^63-1`, negative PHP `int` for `2^63..2^64-1`).
 * Users may pass either `int` or unsigned decimal `string` (for values > PHP_INT_MAX).
 */
final class UInt64
{
    public const string MAX_DECIMAL = '18446744073709551615';
    public const int MAX_BITS = -1; // 0xFFFFFFFFFFFFFFFF

    /**
     * Formats a raw 64-bit unsigned integer bit pattern as an unsigned decimal string.
     */
    public static function toDecimalString(int $bits): string
    {
        if ($bits >= 0) {
            return (string) $bits;
        }
        $half = ($bits >> 1) & PHP_INT_MAX;
        $q = intdiv($half, 5);
        $rem = ($half % 5) * 2 + ($bits & 1);
        return $q . $rem;
    }

    /**
     * Returns PHP `int` if the unsigned value fits in `0..PHP_INT_MAX`, otherwise unsigned decimal `string`.
     */
    public static function toIntOrString(int $bits): int|string
    {
        return $bits >= 0 ? $bits : self::toDecimalString($bits);
    }

    /**
     * Parses an `int` or unsigned decimal `string` into a raw 64-bit unsigned bit pattern.
     */
    public static function fromMixed(int|string $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        return self::fromDecimalString($value);
    }

    /**
     * Parses an unsigned decimal string (`"0"`..`"18446744073709551615"`) into a raw 64-bit PHP `int`.
     */
    public static function fromDecimalString(string $decimal): int
    {
        $s = ltrim($decimal, '0');
        if ($s === '') {
            if ($decimal === '' || !ctype_digit($decimal)) {
                throw new EncodeException("Invalid unsigned 64-bit integer string: '{$decimal}'");
            }
            return 0;
        }
        if (!ctype_digit($s)) {
            throw new EncodeException("Invalid unsigned 64-bit integer string: '{$decimal}'");
        }
        $len = \strlen($s);
        if ($len > 20 || ($len === 20 && strcmp($s, self::MAX_DECIMAL) > 0)) {
            throw new EncodeException("Unsigned 64-bit integer overflow: '{$decimal}'");
        }
        if ($len < 19 || ($len === 19 && strcmp($s, '9223372036854775807') <= 0)) {
            return (int) $s;
        }
        $prefix = (int) substr($s, 0, -1);
        $last = (int) substr($s, -1);
        $hi = ($prefix >> 32) & 0xFFFFFFFF;
        $lo = $prefix & 0xFFFFFFFF;
        $lo10 = $lo * 10 + $last;
        $hi10 = $hi * 10 + (($lo10 >> 32) & 0xFFFFFFFF);
        return (($hi10 & 0xFFFFFFFF) << 32) | ($lo10 & 0xFFFFFFFF);
    }

    /**
     * Compares two raw 64-bit unsigned integer bit patterns in unsigned order.
     */
    public static function compare(int $a, int $b): int
    {
        return ($a ^ PHP_INT_MIN) <=> ($b ^ PHP_INT_MIN);
    }

    /**
     * Adds two raw 64-bit unsigned integer bit patterns modulo 2^64.
     */
    public static function add(int $a, int $b): int
    {
        $lo = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
        $hi = (($a >> 32) & 0xFFFFFFFF) + (($b >> 32) & 0xFFFFFFFF) + (($lo >> 32) & 0xFFFFFFFF);
        return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
    }
}
