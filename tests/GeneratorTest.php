<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FileDescriptorProto;
use OmniBuf\Codegen\Generator;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    public function testRejectsMalformedCodeGeneratorRequests(): void
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

    public function testEmitsCycleGuardedSchemaMetaAndMaskedZeroCheckForOpaqueUint32Setter(): void
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

    private function encodePbVarint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= \chr(($v & 0x7F) | 0x80);
            $v >>= 7;
        }
        return $out . \chr($v & 0x7F);
    }
}
