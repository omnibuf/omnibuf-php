<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

final class WireTest extends TestCase
{
    public function testPrefixVarintRoundTripBoundaries(): void
    {
        $cases = [
            [0, '01'],
            [127, 'ff'],
            [128, '0202'],
            [300, 'b204'],
            [16_383, 'feff'],
            [16_384, '04000200'],
            [536_870_911, 'fcffffff'],
            [536_870_912, '000000002000000000'],
            [PHP_INT_MAX, '00ffffffffffffff7f'],
            [UInt64::MAX_BITS, '00ffffffffffffffff'],
        ];

        foreach ($cases as [$val, $expectedHex]) {
            $encoded = Wire::encodeVarint($val);
            self::assertSame($expectedHex, bin2hex($encoded), "varint {$val}");
            $pos = 0;
            $decoded = Wire::decodeVarint($encoded, $pos, \strlen($encoded));
            self::assertSame($val, $decoded);
            self::assertSame(\strlen($encoded), $pos);
        }
    }

    public function testNonMinimalVarintDecoding(): void
    {
        // 42 encoded as 1-byte (0x55), 2-byte (0xaa00), 4-byte (0x54010000), 9-byte
        foreach (['55', 'aa00', '54010000', '002a00000000000000'] as $hex) {
            $bin = hex2bin($hex);
            self::assertIsString($bin);
            $pos = 0;
            self::assertSame(42, Wire::decodeVarint($bin, $pos, \strlen($bin)));
        }
    }

    public function testReservedPrefixVarintRejected(): void
    {
        $this->expectException(DecodeException::class);
        $pos = 0;
        Wire::decodeVarint("\x08\x00\x00\x00\x00\x00\x00\x00\x00", $pos, 9);
    }

    public function testZigZag64And32Narrowing(): void
    {
        $cases = [
            [0, 0],
            [-1, 1],
            [1, 2],
            [-64, 127],
            [64, 128],
            [PHP_INT_MIN, UInt64::MAX_BITS],
            [PHP_INT_MAX, -2], // 0xFFFFFFFFFFFFFFFE
        ];

        foreach ($cases as [$signed, $unsigned]) {
            self::assertSame($unsigned, Wire::encodeZigZag($signed));
            self::assertSame($signed, Wire::decodeZigZag($unsigned));
        }

        self::assertSame(-1, Wire::narrowInt32(0xFFFFFFFF));
        self::assertSame(0xFFFFFFFF, Wire::narrowUint32(-1));
    }

    public function testUInt64DecimalConversionAndComparison(): void
    {
        self::assertSame('0', UInt64::toDecimalString(0));
        self::assertSame('9223372036854775807', UInt64::toDecimalString(PHP_INT_MAX));
        self::assertSame('9223372036854775808', UInt64::toDecimalString(PHP_INT_MIN));
        self::assertSame('18446744073709551615', UInt64::toDecimalString(UInt64::MAX_BITS));

        self::assertSame(PHP_INT_MIN, UInt64::fromDecimalString('9223372036854775808'));
        self::assertSame(UInt64::MAX_BITS, UInt64::fromDecimalString('18446744073709551615'));
        self::assertSame('14627333968688430831', UInt64::toDecimalString(UInt64::fromDecimalString('14627333968688430831')));

        self::assertLessThan(0, UInt64::compare(PHP_INT_MAX, PHP_INT_MIN));
        self::assertLessThan(0, UInt64::compare(PHP_INT_MIN, UInt64::MAX_BITS));
        self::assertSame(0, UInt64::compare(UInt64::MAX_BITS, UInt64::MAX_BITS));
    }

    public function testSpecNodeExampleBytes(): void
    {
        // Spec Section 9 example: Node { id=300, active=true, location={ city="FRA", rack=42 } }
        $enc = new Encoder();
        $enc->writeUint32Field(1, 300);
        $enc->writeBoolField(3, true);
        $enc->writeTag(9, WireType::SUB_MSG);
        $enc->beginContainer();
        $enc->writeStringField(3, 'FRA');
        $enc->writeUint32Field(4, 42);
        $enc->endContainer();

        self::assertSame('21b2046772021e0075074652418155', bin2hex($enc->finish()));
    }

    public function testStrictUtf8Rejected(): void
    {
        $enc = new Encoder();
        $enc->writeStringField(1, "\xFF\xFE");
        $dec = new Decoder($enc->finish());
        [$num, $wt] = $dec->readTag();
        self::assertSame(1, $num);
        self::assertSame(WireType::STRING, $wt);

        $this->expectException(DecodeException::class);
        $dec->readString();
    }

    public function testLargeContainerBackpatchOver16KiB(): void
    {
        $big = str_repeat('a', 20_000);
        $enc = new Encoder();
        $enc->writeTag(1, WireType::SUB_MSG);
        $enc->beginContainer();
        $enc->writeStringField(1, $big);
        $enc->endContainer();

        $dec = new Decoder($enc->finish());
        [$num, $wt] = $dec->readTag();
        self::assertSame(1, $num);
        self::assertSame(WireType::SUB_MSG, $wt);
        $sub = $dec->readSubMessageDecoder();
        [$innerNum, $innerWt] = $sub->readTag();
        self::assertSame(1, $innerNum);
        self::assertSame(WireType::STRING, $innerWt);
        self::assertSame($big, $sub->readString());
    }
}
