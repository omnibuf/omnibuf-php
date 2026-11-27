<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
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
        try {
            $enc->writeStringField(1, "\xFF\xFE");
            self::fail('Expected EncodeException for invalid UTF-8 on encode');
        } catch (\OmniBuf\Exception\EncodeException) {
            $this->addToAssertionCount(1);
        }

        $raw = Wire::encodeTag(1, WireType::STRING) . Wire::encodeVarint(2) . "\xFF\xFE";
        $dec = new Decoder($raw);
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

    public function testValidateArrayBodyEnforcesMaxElementsForFixedWidthElements(): void
    {
        $enc = new Encoder();
        $enc->writeByte(WireType::FIXED32);
        $enc->writeVarint(5);
        for ($i = 0; $i < 5; $i++) {
            $enc->writeFixed32Raw($i);
        }
        $body = $enc->finish();

        $this->expectException(DecodeException::class);
        Wire::validateArrayBody($body, 0, \strlen($body), 1, Wire::MAX_NESTING_DEPTH, 3, false);
    }

    public function testEncoderWriteTagRejectsOutOfBoundsFieldNumbersAndWireTypes(): void
    {
        $enc = new Encoder();

        $cases = [
            [-1, WireType::UINT],
            [Wire::MAX_FIELD_NUMBER + 1, WireType::UINT],
            [1, -1],
            [1, 16],
        ];

        foreach ($cases as [$num, $wt]) {
            try {
                $enc->writeTag($num, $wt);
                self::fail("Expected EncodeException for field={$num}, wt={$wt}");
            } catch (EncodeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSkipValueValidatesArrayAndMapHeadersInO1(): void
    {
        // Valid WireArray payloads: empty, fixed-width, variable-width (including shallow skip over element bytes)
        $validArrays = [
            \chr(WireType::UINT) . Wire::encodeVarint(0),
            \chr(WireType::FIXED32) . Wire::encodeVarint(2) . str_repeat("\x01", 8),
            \chr(WireType::BOOL_FALSE) . Wire::encodeVarint(2) . "\x02\x03", // shallow skip does not iterate bool elements
            \chr(WireType::STRING) . Wire::encodeVarint(1) . Wire::encodeVarint(2) . "\xFF\xFE", // shallow skip does not validate UTF-8
        ];
        foreach ($validArrays as $body) {
            $val = Wire::encodeVarint(\strlen($body)) . $body;
            $pos = 0;
            Wire::skipValue($val, $pos, \strlen($val), WireType::ARRAY);
            self::assertSame(\strlen($val), $pos);
        }

        // Valid WireMap payloads: empty and non-empty
        $validMaps = [
            \chr(WireType::STRING) . \chr(WireType::UINT) . Wire::encodeVarint(0),
            \chr(WireType::STRING) . \chr(WireType::UINT) . Wire::encodeVarint(1) . Wire::encodeVarint(1) . 'k' . Wire::encodeVarint(7),
        ];
        foreach ($validMaps as $body) {
            $val = Wire::encodeVarint(\strlen($body)) . $body;
            $pos = 0;
            Wire::skipValue($val, $pos, \strlen($val), WireType::MAP);
            self::assertSame(\strlen($val), $pos);
        }

        // Corrupt WireArray headers rejected in O(1)
        $corruptArrays = [
            'truncated' => "\x00",
            'invalid elem type BOOL_TRUE' => \chr(WireType::BOOL_TRUE) . Wire::encodeVarint(0),
            'invalid elem type 16' => "\x10" . Wire::encodeVarint(0),
            'count 0 with trailing bytes' => \chr(WireType::UINT) . Wire::encodeVarint(0) . "\x01",
            'fixed-width non-divisible remainder' => \chr(WireType::FIXED32) . Wire::encodeVarint(1) . str_repeat("\x00", 5),
            'fixed-width count mismatch' => \chr(WireType::FIXED32) . Wire::encodeVarint(2) . str_repeat("\x00", 4),
            'variable-width count exceeds rem' => \chr(WireType::UINT) . Wire::encodeVarint(5) . "\x01\x01",
            'count overflow' => \chr(WireType::UINT) . Wire::encodeVarint(UInt64::MAX_BITS),
        ];
        foreach ($corruptArrays as $label => $body) {
            $val = Wire::encodeVarint(\strlen($body)) . $body;
            $pos = 0;
            try {
                Wire::skipValue($val, $pos, \strlen($val), WireType::ARRAY);
                self::fail("Expected DecodeException for corrupt array: {$label}");
            } catch (DecodeException) {
                $this->addToAssertionCount(1);
            }
        }

        // Corrupt WireMap headers rejected in O(1)
        $corruptMaps = [
            'truncated' => "\x0a\x00",
            'invalid key type BYTES' => \chr(WireType::BYTES) . \chr(WireType::UINT) . Wire::encodeVarint(0),
            'invalid key type BOOL_TRUE' => \chr(WireType::BOOL_TRUE) . \chr(WireType::UINT) . Wire::encodeVarint(0),
            'invalid val type BOOL_TRUE' => \chr(WireType::STRING) . \chr(WireType::BOOL_TRUE) . Wire::encodeVarint(0),
            'invalid val type 16' => \chr(WireType::STRING) . "\x10" . Wire::encodeVarint(0),
            'count 0 with trailing bytes' => \chr(WireType::STRING) . \chr(WireType::UINT) . Wire::encodeVarint(0) . "\x01",
            'count exceeds rem/2' => \chr(WireType::STRING) . \chr(WireType::UINT) . Wire::encodeVarint(2) . "\x01\x01\x01",
            'count overflow' => \chr(WireType::STRING) . \chr(WireType::UINT) . Wire::encodeVarint(UInt64::MAX_BITS),
        ];
        foreach ($corruptMaps as $label => $body) {
            $val = Wire::encodeVarint(\strlen($body)) . $body;
            $pos = 0;
            try {
                Wire::skipValue($val, $pos, \strlen($val), WireType::MAP);
                self::fail("Expected DecodeException for corrupt map: {$label}");
            } catch (DecodeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSpliceFieldsRejectsCorruptArrayAndMapHeaders(): void
    {
        $badArrayBody = \chr(WireType::FIXED32) . Wire::encodeVarint(2) . str_repeat("\x00", 4);
        $badArrayPacket = Wire::encodeTag(1, WireType::UINT) . Wire::encodeVarint(7)
            . Wire::encodeTag(2, WireType::ARRAY) . Wire::encodeVarint(\strlen($badArrayBody)) . $badArrayBody;

        $badMapBody = \chr(WireType::BYTES) . \chr(WireType::UINT) . Wire::encodeVarint(0);
        $badMapPacket = Wire::encodeTag(1, WireType::UINT) . Wire::encodeVarint(7)
            . Wire::encodeTag(2, WireType::MAP) . Wire::encodeVarint(\strlen($badMapBody)) . $badMapBody;

        $repl = [1 => Wire::encodeTag(1, WireType::UINT) . Wire::encodeVarint(99)];

        foreach ([$badArrayPacket, $badMapPacket] as $pkt) {
            foreach ([[], $repl] as $r) {
                try {
                    Wire::spliceFields('', $pkt, $r);
                    self::fail('Expected DecodeException for corrupt container header in spliceFields');
                } catch (DecodeException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
