<?php

declare(strict_types=1);

namespace OmniBuf;

final class WireType
{
    public const int UINT = 0;
    public const int SINT = 1;
    public const int BOOL_FALSE = 2;
    public const int BOOL_TRUE = 3;
    public const int FIXED32 = 4;
    public const int FIXED64 = 5;
    public const int SFIXED32 = 6;
    public const int SFIXED64 = 7;
    public const int FLOAT32 = 8;
    public const int FLOAT64 = 9;
    public const int STRING = 10;
    public const int BYTES = 11;
    public const int SUB_MSG = 12;
    public const int ARRAY = 13;
    public const int MAP = 14;
    public const int EXTENDED = 15;

    private const array NAMES = [
        self::UINT => 'WireUint',
        self::SINT => 'WireSint',
        self::BOOL_FALSE => 'WireBoolFalse',
        self::BOOL_TRUE => 'WireBoolTrue',
        self::FIXED32 => 'WireFixed32',
        self::FIXED64 => 'WireFixed64',
        self::SFIXED32 => 'WireSfixed32',
        self::SFIXED64 => 'WireSfixed64',
        self::FLOAT32 => 'WireFloat32',
        self::FLOAT64 => 'WireFloat64',
        self::STRING => 'WireString',
        self::BYTES => 'WireBytes',
        self::SUB_MSG => 'WireSubMsg',
        self::ARRAY => 'WireArray',
        self::MAP => 'WireMap',
        self::EXTENDED => 'WireExtended',
    ];

    public static function name(int $wireType): string
    {
        return self::NAMES[$wireType] ?? "Unknown({$wireType})";
    }

    public static function isValidCollectionType(int $wireType): bool
    {
        return $wireType >= 0 && $wireType <= 15 && $wireType !== self::BOOL_TRUE;
    }

    public static function isValidKeyType(int $wireType): bool
    {
        return match ($wireType) {
            self::UINT,
            self::SINT,
            self::BOOL_FALSE,
            self::FIXED32,
            self::FIXED64,
            self::SFIXED32,
            self::SFIXED64,
            self::STRING => true,
            default => false,
        };
    }

    /**
     * Returns fixed byte width inside a collection (WireArray/WireMap), or 0 for variable-width types.
     */
    public static function collectionFixedWidth(int $wireType): int
    {
        return match ($wireType) {
            self::BOOL_FALSE => 1,
            self::FIXED32, self::SFIXED32, self::FLOAT32 => 4,
            self::FIXED64, self::SFIXED64, self::FLOAT64 => 8,
            default => 0,
        };
    }
}
