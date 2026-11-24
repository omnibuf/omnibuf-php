<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\BlindDecoder;
use OmniBuf\Encoder;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

final class BlindDecoderTest extends TestCase
{
    public function testDecodesStringArraysContainingInternalSentinelPrefixesWithoutCollision(): void
    {
        $enc = new Encoder();
        // Field 1: array of strings ["\x00u64", "1, \"injected\": true"]
        $enc->writeTag(1, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(2);
        $enc->writeRawStringPayload("\x00u64");
        $enc->writeRawStringPayload('1, "injected": true');
        $enc->endContainer();

        // Field 2: array of strings ["\x00obj", "not-an-array"]
        $enc->writeTag(2, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(2);
        $enc->writeRawStringPayload("\x00obj");
        $enc->writeRawStringPayload('not-an-array');
        $enc->endContainer();

        // Field 3: array of strings ["\x00bytes", "raw"]
        $enc->writeTag(3, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(2);
        $enc->writeRawStringPayload("\x00bytes");
        $enc->writeRawStringPayload('raw');
        $enc->endContainer();

        $packet = $enc->finish();

        $decoded = BlindDecoder::decode($packet);
        self::assertSame(["\x00u64", '1, "injected": true'], $decoded['field_1']);
        self::assertSame(["\x00obj", 'not-an-array'], $decoded['field_2']);
        self::assertSame(["\x00bytes", 'raw'], $decoded['field_3']);

        $json = BlindDecoder::decodeJson($packet);
        $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(["\x00u64", '1, "injected": true'], $parsed['field_1']);
        self::assertSame(["\x00obj", 'not-an-array'], $parsed['field_2']);
        self::assertSame(["\x00bytes", 'raw'], $parsed['field_3']);
    }
}
