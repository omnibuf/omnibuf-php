<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Opaque;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

final class OpaqueTest extends TestCase
{
    public function testGetSfixed32MatchesOnlySfixed32WireType(): void
    {
        $enc = new Encoder();
        $enc->writeFixed32Field(1, 999);
        $enc->writeSfixed32Field(2, -123456);
        $buf = $enc->finish();

        // Field 1 is FIXED32, so getSfixed32 must return default 0
        self::assertSame(0, Opaque::getSfixed32($buf, 0, \strlen($buf), 1));
        // Field 2 is SFIXED32, so getSfixed32 must decode -123456
        self::assertSame(-123456, Opaque::getSfixed32($buf, 0, \strlen($buf), 2));
    }

    public function testGetStringArrayEnforcesMaxElements(): void
    {
        $enc = new Encoder();
        $enc->writeTag(1, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(4);
        $enc->writeRawStringPayload('a');
        $enc->writeRawStringPayload('b');
        $enc->writeRawStringPayload('c');
        $enc->writeRawStringPayload('d');
        $enc->endContainer();
        $buf = $enc->finish();

        self::assertSame(['a', 'b', 'c', 'd'], Opaque::getStringArray($buf, 0, \strlen($buf), 1, 4));
        $this->expectException(DecodeException::class);
        Opaque::getStringArray($buf, 0, \strlen($buf), 1, 3);
    }

    public function testGetUint32ArrayEnforcesMaxElements(): void
    {
        $enc = new Encoder();
        $enc->writeTag(1, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::UINT);
        $enc->writeVarint(4);
        $enc->writeVarint(1);
        $enc->writeVarint(2);
        $enc->writeVarint(3);
        $enc->writeVarint(4);
        $enc->endContainer();
        $buf = $enc->finish();

        self::assertSame([1, 2, 3, 4], Opaque::getUint32Array($buf, 0, \strlen($buf), 1, 4));
        $this->expectException(DecodeException::class);
        Opaque::getUint32Array($buf, 0, \strlen($buf), 1, 3);
    }
}
