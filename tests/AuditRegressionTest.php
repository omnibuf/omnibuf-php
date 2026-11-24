<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FileDescriptorProto;
use OmniBuf\AttributeCodec;
use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use OmniBuf\BlindDecoder;
use OmniBuf\Codegen\Generator;
use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
use OmniBuf\Opaque;
use OmniBuf\Wire;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

#[OmniBufMessage('FloatSubnormalMsg')]
final class FloatSubnormalMsg
{
    #[OmniBufField(1, wire: 'float32', omitEmpty: true)]
    public float $f32 = 0.0;
}

#[OmniBufMessage('CyclicAttrNode')]
final class CyclicAttrNode
{
    #[OmniBufField(1, wire: 'uint32')]
    public int $id = 0;

    #[OmniBufField(2, wire: 'message', messageClass: self::class)]
    public ?self $next = null;
}

#[OmniBufMessage('InvalidNumMsg')]
final class InvalidNumMsg
{
    #[OmniBufField(0, wire: 'uint32')]
    public int $bad = 0;
}

#[OmniBufMessage('DupNumMsg')]
final class DupNumMsg
{
    #[OmniBufField(1, wire: 'uint32')]
    public int $a = 0;

    #[OmniBufField(1, wire: 'string')]
    public string $b = '';
}

final class NativeChildModel
{
    public int $id = 0;
    public string $name = '';
}

final class NativeParentModel
{
    public static int $staticProp = 1;
    public int $u32 = 0;
    public int $s32 = 0;
    public string $label = '';
    public ?object $child = null;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<object> */
    public array $children = [];
}

final class NativeLargeFieldModel
{
    public int $bigField = 0;
}

final class AuditRegressionTest extends TestCase
{
    public function testBlindDecoderSentinelCollisionPrevented(): void
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

    public function testOpaqueGetSfixed32ReadsSfixed32WireType(): void
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

    public function testOpaqueArrayMaxElementsEnforced(): void
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

    public function testOpaqueUint32ArrayMaxElementsEnforced(): void
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

    public function testValidateArrayBodyChecksMaxElementsBeforeFixedWidthReturn(): void
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

    public function testEncoderWriteTagValidatesBounds(): void
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

    public function testAttributeCodecFloat32SubnormalOmittedAndNegZeroPreserved(): void
    {
        $msg = new FloatSubnormalMsg();
        $msg->f32 = 1e-50; // underflows to +0.0 in IEEE 754 float32
        self::assertSame('', AttributeCodec::marshal($msg));

        $msg->f32 = -0.0;
        self::assertNotSame('', AttributeCodec::marshal($msg));
    }

    public function testAttributeCodecCyclicObjectHitsDepthLimit(): void
    {
        $a = new CyclicAttrNode();
        $a->id = 1;
        $a->next = $a;

        $this->expectException(EncodeException::class);
        AttributeCodec::marshal($a);
    }

    public function testAttributeCodecRejectsInvalidAndDuplicateFieldNumbers(): void
    {
        try {
            AttributeCodec::marshal(new InvalidNumMsg());
            self::fail('Expected EncodeException for field number 0');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(EncodeException::class);
        AttributeCodec::marshal(new DupNumMsg());
    }

    public function testCodegenRejectsMalformedCodeGeneratorRequest(): void
    {
        $gen = new Generator();

        // 1. Varint overflow (>= 64 bits of continuation bytes)
        $overflow = str_repeat("\x80", 11) . "\x01";
        $resp = $gen->run($overflow);
        self::assertStringContainsString('varint overflow', strtolower($resp));

        // 2. Truncated length-delimited field (tag=0x0a, len=100, only 2 bytes follow)
        $truncLen = "\x0a\x64ab";
        $resp2 = $gen->run($truncLen);
        self::assertStringContainsString('truncated', strtolower($resp2));
    }

    public function testCodegenCyclicSchemaMetaAndOpaqueZeroSetter(): void
    {
        $fdp = new FileDescriptorProto();
        $fdp->setName('cyclic.proto');
        $fdp->setPackage('test.cyclic');

        $msg = new DescriptorProto();
        $msg->setName('TreeNode');

        $f1 = new FieldDescriptorProto();
        $f1->setName('id');
        $f1->setNumber(1);
        $f1->setLabel(Label::LABEL_OPTIONAL);
        $f1->setType(Type::TYPE_UINT32);

        $f2 = new FieldDescriptorProto();
        $f2->setName('child');
        $f2->setNumber(2);
        $f2->setLabel(Label::LABEL_OPTIONAL);
        $f2->setType(Type::TYPE_MESSAGE);
        $f2->setTypeName('.test.cyclic.TreeNode');

        $msg->setField([$f1, $f2]);
        $fdp->setMessageType([$msg]);

        // Build minimal CodeGeneratorRequest
        $fdpBytes = $fdp->serializeToString();
        $req = "\x0a" . \chr(\strlen('cyclic.proto')) . 'cyclic.proto'
            . "\x7a" . $this->encodePbVarint(\strlen($fdpBytes)) . $fdpBytes;

        $gen = new Generator();
        $resp = $gen->run($req);

        self::assertStringContainsString('array $visited = []', $resp);
        self::assertStringContainsString('if (!isset($visited[\\Test\\Cyclic\\TreeNode::class]))', $resp);
        self::assertStringContainsString('if (($value & 0xFFFFFFFF) !== 0)', $resp);
    }

    public function testNativeExtensionHardening(): void
    {
        if (!\function_exists('omnibuf_native_register_schema')) {
            $this->markTestSkipped('ext-omnibuf is not loaded');
        }

        // 1. Reject invalid schema registrations
        try {
            \omnibuf_native_register_schema(NativeParentModel::class, [[0, 1, 'u32']]);
            self::fail('Expected exception for field number 0');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        try {
            \omnibuf_native_register_schema(NativeParentModel::class, [[536870912, 1, 'u32']]);
            self::fail('Expected exception for field number > 536870911');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        try {
            \omnibuf_native_register_schema(NativeParentModel::class, [[1, 1, 'staticProp']]);
            self::fail('Expected exception for static property');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        try {
            \omnibuf_native_register_schema(NativeParentModel::class, [[1, 10, 'child', 99999]]);
            self::fail('Expected exception for invalid sub_schema_id');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        // 2. Large field number (536870911) works without 1 GiB allocation
        $bigId = \omnibuf_native_register_schema(NativeLargeFieldModel::class, [
            [536870911, 1, 'bigField'],
        ]);
        $big = new NativeLargeFieldModel();
        $big->bigField = 42;
        $bigWire = \omnibuf_native_encode($bigId, $big);
        /** @var NativeLargeFieldModel $bigDec */
        $bigDec = \omnibuf_native_decode($bigId, $bigWire);
        self::assertSame(42, $bigDec->bigField);

        // 3. Register valid child + parent schemas
        $childId = \omnibuf_native_register_schema(NativeChildModel::class, [
            [1, 1, 'id'],
            [2, 8, 'name'],
        ]);
        $parentId = \omnibuf_native_register_schema(NativeParentModel::class, [
            [1, 1, 'u32'],
            [2, 3, 's32'],
            [3, 8, 'label'],
            [4, 10, 'child', $childId],
            [5, 13, 'tags'],
            [6, 14, 'children', $childId],
        ]);

        // 4. Class mismatch on top-level encode
        try {
            \omnibuf_native_encode($parentId, new NativeChildModel());
            self::fail('Expected EncodeException on top-level class mismatch');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        // 5. Class mismatch on submessage encode
        $badParent = new NativeParentModel();
        $badParent->child = new \stdClass();
        try {
            \omnibuf_native_encode($parentId, $badParent);
            self::fail('Expected EncodeException on FK_SUBMSG class mismatch');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        // 6. Class mismatch on array submessage encode
        $badArrParent = new NativeParentModel();
        $badArrParent->children = [new \stdClass()];
        try {
            \omnibuf_native_encode($parentId, $badArrParent);
            self::fail('Expected EncodeException on FK_ARR_SUBMSG class mismatch');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        // 7. Narrowing zero check on FK_UINT32 / FK_SINT32
        $narrow = new NativeParentModel();
        $narrow->u32 = 1 << 32; // narrows to 0 in uint32
        $narrow->s32 = 1 << 32; // narrows to 0 in sint32
        self::assertSame('', \omnibuf_native_encode($parentId, $narrow));

        // 8. Decode OOM DoS prevention on huge array count
        $enc = new Encoder();
        $enc->writeTag(5, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(0x7FFFFFFF);
        $enc->endContainer();
        try {
            \omnibuf_native_decode($parentId, $enc->finish());
            self::fail('Expected DecodeException for OOM array count');
        } catch (DecodeException) {
            $this->addToAssertionCount(1);
        }

        // 9. Decode Field #0 with non-SUBMSG wire type rejected
        $enc = new Encoder();
        $enc->writeUint32Field(0, 1);
        try {
            \omnibuf_native_decode($parentId, $enc->finish());
            self::fail('Expected DecodeException for Field #0 with WT_UINT');
        } catch (DecodeException) {
            $this->addToAssertionCount(1);
        }

        // 10. Decode repeated array appends & repeated submsg merges & mismatched wt skipped
        $enc = new Encoder();
        // Mismatched wire type for field 1 (STRING instead of UINT) -> must skip cleanly
        $enc->writeStringField(1, 'wrong-type');
        // Valid field 3
        $enc->writeStringField(3, 'ok');
        // Repeated submsg field 4: first sets id=7, second sets name='alice'
        $enc->writeTag(4, WireType::SUB_MSG);
        $enc->beginContainer();
        $enc->writeUint32Field(1, 7);
        $enc->endContainer();
        $enc->writeTag(4, WireType::SUB_MSG);
        $enc->beginContainer();
        $enc->writeStringField(2, 'alice');
        $enc->endContainer();
        // Repeated array field 5: ['a'] then ['b']
        $enc->writeTag(5, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(1);
        $enc->writeRawStringPayload('a');
        $enc->endContainer();
        $enc->writeTag(5, WireType::ARRAY);
        $enc->beginContainer();
        $enc->writeByte(WireType::STRING);
        $enc->writeVarint(1);
        $enc->writeRawStringPayload('b');
        $enc->endContainer();

        /** @var NativeParentModel $decoded */
        $decoded = \omnibuf_native_decode($parentId, $enc->finish());
        self::assertSame(0, $decoded->u32);
        self::assertSame('ok', $decoded->label);
        self::assertInstanceOf(NativeChildModel::class, $decoded->child);
        self::assertSame(7, $decoded->child->id);
        self::assertSame('alice', $decoded->child->name);
        self::assertSame(['a', 'b'], $decoded->tags);

        // 11. Native opaque get_string validates UTF-8
        $badUtf8 = Wire::encodeTag(1, WireType::STRING) . Wire::encodeVarint(2) . "\xFF\xFE";
        try {
            \omnibuf_native_opaque_get_string($badUtf8, 0, \strlen($badUtf8), 1);
            self::fail('Expected DecodeException for invalid UTF-8 in native opaque_get_string');
        } catch (DecodeException) {
            $this->addToAssertionCount(1);
        }

        // 12. Native opaque splice rejects string keys
        try {
            /** @phpstan-ignore argument.type */
            \omnibuf_native_opaque_splice('', '', ['not_an_int' => 'abc']);
            self::fail('Expected DecodeException for string key in native opaque_splice');
        } catch (DecodeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function encodePbVarint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= \chr(($v & 0x7F) | 0x80);
            $v >>= 7;
        }
        return $out . \chr($v);
    }
}
