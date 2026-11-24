<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\OmniBuf;
use OmniBuf\Wire;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

final class NativeExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('omnibuf_native_opaque_splice')) {
            $this->markTestSkipped('ext-omnibuf is not loaded');
        }
    }

    public function testModuleVersionMatchesLibraryVersion(): void
    {
        self::assertSame(OmniBuf::VERSION, phpversion('omnibuf'));
    }

    public function testOpaqueSpliceReplacesFieldAndPreservesPrefix(): void
    {
        $enc = new Encoder();
        $enc->writeUint32Field(1, 10);
        $enc->writeStringField(2, 'before');
        $raw = $enc->finish();

        $replacement = Wire::encodeTag(2, WireType::STRING) . Wire::encodeVarint(5) . 'after';
        $spliced = \omnibuf_native_opaque_splice('', $raw, [2 => $replacement]);

        $decoded = OmniBuf::decodeBlind($spliced);
        self::assertSame(['field_1' => 10, 'field_2' => 'after'], $decoded);
    }

    public function testOpaqueSpliceRejectsStringKeys(): void
    {
        $this->expectException(DecodeException::class);
        /** @phpstan-ignore argument.type */
        \omnibuf_native_opaque_splice('', '', ['not_an_int' => 'abc']);
    }

    public function testOpaqueSpliceRejectsMalformedPacket(): void
    {
        $this->expectException(DecodeException::class);
        \omnibuf_native_opaque_splice('', "\x12\x00", [1 => '']);
    }

    public function testDecodeBlindJsonDecodesValidPacket(): void
    {
        $enc = new Encoder();
        $enc->writeUint32Field(1, 42);
        $enc->writeStringField(2, 'ok');
        $json = \omnibuf_native_decode_blind_json($enc->finish());

        self::assertSame(['field_1' => 42, 'field_2' => 'ok'], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDecodeBlindJsonRejectsInvalidUtf8(): void
    {
        $badUtf8 = Wire::encodeTag(1, WireType::STRING) . Wire::encodeVarint(2) . "\xFF\xFE";

        $this->expectException(DecodeException::class);
        \omnibuf_native_decode_blind_json($badUtf8);
    }
}
