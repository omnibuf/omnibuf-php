<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\Edition;
use Google\Protobuf\Internal\FeatureSet;
use Google\Protobuf\Internal\FeatureSet\FieldPresence;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FieldOptions;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\FileOptions;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\MessageOptions;
use Google\Protobuf\Internal\OneofDescriptorProto;
use Google\Protobuf\Internal\OneofOptions;
use OmniBuf\Codegen\EditionResolver;
use OmniBuf\Codegen\Generator;
use OmniBuf\Encoder;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
use OmniBuf\GeneratedMessage;
use OmniBuf\Internal\ProtobufEditionMetadata;
use OmniBuf\OmniBuf;
use OmniBuf\ProtobufCodec;
use OmniBuf\Wire;
use OmniBuf\WireType;
use PHPUnit\Framework\TestCase;

final class EditionsTest extends TestCase
{
    private static string $tmpDir = '';
    private static string $protocBin = '';

    public static function setUpBeforeClass(): void
    {
        self::$protocBin = self::locateProtoc();
        self::$tmpDir = sys_get_temp_dir() . '/omnibuf_php_editions_' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir . '/proto', 0o755, true);
        mkdir(self::$tmpDir . '/gen', 0o755, true);
        mkdir(self::$tmpDir . '/php', 0o755, true);

        self::writeProtoFixtures();

        // Compile Editions 2023, 2024, and 2026 schemas with both protoc-gen-omnibuf-php and protoc --php_out
        self::runProtoc(
            ['ed2023.proto', 'ed2024.proto', 'ed2026.proto'],
            true,
            true,
            'EditionsGen',
        );

        // Compile DELIMITED + option-extension Edition 2023 schema with protoc-gen-omnibuf-php
        // (protoc --php_out does not support group/DELIMITED fields)
        self::runProtoc(
            ['ed2023_delim.proto'],
            true,
            false,
            'EditionsGen',
        );

        // Compile negative Editions schemas accepted by protoc --php_out to verify ProtobufCodec rejection
        self::runProtoc(
            ['bad_utf8_scalar.proto', 'bad_utf8_map.proto', 'bad_default.proto'],
            false,
            true,
            '',
        );

        self::loadPhpDirectory(self::$tmpDir . '/gen');
        self::loadPhpDirectory(self::$tmpDir . '/php');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tmpDir !== '' && is_dir(self::$tmpDir)) {
            self::removeDirRecursive(self::$tmpDir);
        }
    }

    public function testPluginAdvertisesEditionsCapabilitiesOnSuccessAndError(): void
    {
        $gen = new Generator();

        // Valid Edition 2023 request
        $fdp = new FileDescriptorProto();
        $fdp->setName('cap.proto');
        $fdp->setPackage('cap.test');
        $fdp->setSyntax('editions');
        $fdp->setEdition(Edition::EDITION_2023);
        $msg = new DescriptorProto();
        $msg->setName('Ping');
        $f = new FieldDescriptorProto();
        $f->setName('id');
        $f->setNumber(1);
        $f->setLabel(Label::LABEL_OPTIONAL);
        $f->setType(Type::TYPE_INT32);
        $msg->setField([$f]);
        $fdp->setMessageType([$msg]);

        $okResp = $this->parseCodeGenResponse($gen->run($this->buildCodeGenRequest([$fdp], ['cap.proto'])));
        self::assertNull($okResp['error']);
        self::assertSame(3, $okResp['supported_features']);
        self::assertSame(EditionResolver::MIN_EDITION, $okResp['minimum_edition']);
        self::assertSame(EditionResolver::MAX_EDITION, $okResp['maximum_edition']);
        self::assertCount(2, $okResp['files']);

        // Error response (unsupported edition 1003) must still advertise capabilities
        $badFdp = new FileDescriptorProto();
        $badFdp->setName('future.proto');
        $badFdp->setSyntax('editions');
        $badFdp->setEdition(1003);

        $errResp = $this->parseCodeGenResponse($gen->run($this->buildCodeGenRequest([$badFdp], ['future.proto'])));
        self::assertNotNull($errResp['error']);
        self::assertStringContainsString('unsupported protobuf edition', strtolower($errResp['error']));
        self::assertSame(3, $errResp['supported_features']);
        self::assertSame(EditionResolver::MIN_EDITION, $errResp['minimum_edition']);
        self::assertSame(EditionResolver::MAX_EDITION, $errResp['maximum_edition']);
        self::assertSame([], $errResp['files']);
    }

    public function testEdition2023ExplicitVsAbsentAndTier2Equivalence(): void
    {
        $genCls = 'EditionsGen\\Ed23\\Sample2023';
        $childCls = 'EditionsGen\\Ed23\\Child';
        $pbCls = self::pbClass('Ed23\\Sample2023');
        $pbChildCls = 'Ed23\\Child';

        // 1. All fields absent -> empty wire
        $absent = self::make($genCls);
        self::assertNull($absent->expInt);
        self::assertNull($absent->expBool);
        self::assertNull($absent->expStr);
        self::assertNull($absent->expEnum);
        self::assertSame(0, $absent->impInt);
        self::assertFalse($absent->impBool);
        self::assertSame('', $absent->impStr);

        $wireAbsent = self::asGen($absent)->marshalOmnibuf(true);
        self::assertSame('', $wireAbsent);

        $pbAbsent = self::make($pbCls);
        self::assertSame('', ProtobufCodec::marshal(self::asMessage($pbAbsent), true));

        // Unmarshaling empty wire into a pre-populated Tier 2 Editions message must clear presence (has*() === false)
        $pbDirty = self::make($pbCls);
        $pbDirty->setExpInt(99);
        $pbDirty->setExpBool(true);
        $pbDirty->setExpStr('stale');
        $pbDirty->setExpEnum(2);
        ProtobufCodec::unmarshal('', self::asMessage($pbDirty), false);
        self::assertFalse($pbDirty->hasExpInt());
        self::assertFalse($pbDirty->hasExpBool());
        self::assertFalse($pbDirty->hasExpStr());
        self::assertFalse($pbDirty->hasExpEnum());
        self::assertSame('', ProtobufCodec::marshal(self::asMessage($pbDirty), true));

        // 2. Explicit zero/false/empty values MUST be encoded on the wire and differ from absent
        $zeros = self::make(
            $genCls,
            expInt: 0,
            expUint: 0,
            expInt64: 0,
            expBool: false,
            expFloat: 0.0,
            expDouble: 0.0,
            expStr: '',
            expBytes: '',
            expEnum: 0,
            zeroDef: 0,
            impInt: 0,
            impBool: false,
            impStr: '',
        );
        $wireZeros = self::asGen($zeros)->marshalOmnibuf(true);
        self::assertNotSame('', $wireZeros);

        // Verify field #4 (exp_bool = false) is encoded with WireType::BOOL_FALSE tag
        self::assertStringContainsString(Wire::encodeTag(4, WireType::BOOL_FALSE), $wireZeros);

        // Round-trip Tier 1: explicit zeros survive while implicit fields remain omitted
        $rtZeros = self::unmarshalGen($genCls, $wireZeros);
        self::assertSame(0, $rtZeros->expInt);
        self::assertSame(0, $rtZeros->expUint);
        self::assertSame(0, $rtZeros->expInt64);
        self::assertFalse($rtZeros->expBool);
        self::assertSame(0.0, $rtZeros->expFloat);
        self::assertSame(0.0, $rtZeros->expDouble);
        self::assertSame('', $rtZeros->expStr);
        self::assertSame('', $rtZeros->expBytes);
        self::assertSame(0, $rtZeros->expEnum);
        self::assertSame(0, $rtZeros->zeroDef);
        self::assertSame(0, $rtZeros->impInt);
        self::assertFalse($rtZeros->impBool);
        self::assertSame('', $rtZeros->impStr);
        self::assertSame($wireZeros, self::asGen($rtZeros)->marshalOmnibuf(true));

        // Byte-for-byte wire equivalence with Tier 2 ProtobufCodec
        $pbZeros = self::make($pbCls);
        $pbZeros->setExpInt(0);
        $pbZeros->setExpUint(0);
        $pbZeros->setExpInt64(0);
        $pbZeros->setExpBool(false);
        $pbZeros->setExpFloat(0.0);
        $pbZeros->setExpDouble(0.0);
        $pbZeros->setExpStr('');
        $pbZeros->setExpBytes('');
        $pbZeros->setExpEnum(0);
        $pbZeros->setZeroDef(0);
        $pbZeros->setImpInt(0);
        $pbZeros->setImpBool(false);
        $pbZeros->setImpStr('');
        $wirePbZeros = ProtobufCodec::marshal(self::asMessage($pbZeros), true);
        self::assertSame($wireZeros, $wirePbZeros);

        $pbRtZeros = self::unmarshalPb($wireZeros, 'Ed23\\Sample2023');
        self::assertTrue($pbRtZeros->hasExpInt());
        self::assertSame(0, $pbRtZeros->getExpInt());
        self::assertTrue($pbRtZeros->hasExpBool());
        self::assertFalse($pbRtZeros->getExpBool());
        self::assertTrue($pbRtZeros->hasExpStr());
        self::assertSame('', $pbRtZeros->getExpStr());
        self::assertTrue($pbRtZeros->hasExpEnum());
        self::assertSame(0, $pbRtZeros->getExpEnum());
        self::assertSame($wireZeros, ProtobufCodec::marshal(self::asMessage($pbRtZeros), true));

        // 3. Full payload: open enum (unknown value 777), PACKED & EXPANDED repeated, maps, nested child
        $full = self::make(
            $genCls,
            expInt: -42,
            expBool: true,
            expStr: 'hello editions',
            expEnum: 777,
            impInt: 12,
            impBool: true,
            impStr: 'imp',
            child: self::make($childCls, id: 7, note: 'nested'),
            repPacked: [1, -2, 3],
            repExpanded: [10, 20, 30],
            repChildren: [self::make($childCls, id: 1, note: 'a'), self::make($childCls, id: 2, note: 'b')],
            scores: ['beta' => 2, 'alpha' => 1],
            childMap: ['k1' => self::make($childCls, id: 100, note: 'mapped')],
        );
        $wireFull = self::asGen($full)->marshalOmnibuf(true);

        $rtFull = self::unmarshalGen($genCls, $wireFull);
        self::assertSame(-42, $rtFull->expInt);
        self::assertTrue($rtFull->expBool);
        self::assertSame('hello editions', $rtFull->expStr);
        self::assertSame(777, $rtFull->expEnum);
        self::assertSame([1, -2, 3], $rtFull->repPacked);
        self::assertSame([10, 20, 30], $rtFull->repExpanded);
        self::assertCount(2, $rtFull->repChildren);
        self::assertSame(2, $rtFull->repChildren[1]->id);
        self::assertSame(['alpha' => 1, 'beta' => 2], $rtFull->scores);
        self::assertSame('mapped', $rtFull->childMap['k1']->note);

        // Tier 2 decode -> re-marshal must produce identical wire bytes (including unknown enum 777)
        $pbFull = self::unmarshalPb($wireFull, 'Ed23\\Sample2023');
        self::assertSame(777, $pbFull->getExpEnum());
        self::assertSame([10, 20, 30], iterator_to_array($pbFull->getRepExpanded()));
        self::assertSame('mapped', $pbFull->getChildMap()['k1']->getNote());
        self::assertSame($wireFull, ProtobufCodec::marshal(self::asMessage($pbFull), true));

        // Direct construction via Tier 2 -> Tier 1 decode
        $pbDirect = self::make($pbCls);
        $pbDirect->setExpEnum(-5);
        $pbDirect->setChild(self::make($pbChildCls, ['id' => 0, 'note' => '']));
        $wirePbDirect = ProtobufCodec::marshal(self::asMessage($pbDirect), true);
        $genFromPb = self::unmarshalGen($genCls, $wirePbDirect);
        self::assertSame(-5, $genFromPb->expEnum);
        self::assertNotNull($genFromPb->child);
        self::assertSame(0, $genFromPb->child->id);
        self::assertSame('', $genFromPb->child->note);
    }

    public function testEdition2023OneofEagerAndProtobufCodec(): void
    {
        $genCls = 'EditionsGen\\Ed23\\Sample2023';
        $childCls = 'EditionsGen\\Ed23\\Child';
        $pbCls = self::pbClass('Ed23\\Sample2023');

        // Default: no branch set
        $m = self::make($genCls);
        self::assertSame(0, $m->getChoiceCase());
        self::assertNull($m->oneofInt);
        self::assertNull($m->oneofBool);
        self::assertNull($m->oneofStr);
        self::assertNull($m->oneofChild);

        // Active zero int branch
        $m->setOneofInt(0);
        self::assertSame(20, $m->getChoiceCase());
        self::assertSame(0, $m->oneofInt);
        $wireInt0 = self::asGen($m)->marshalOmnibuf(true);
        self::assertNotSame('', $wireInt0);
        $rtInt0 = self::unmarshalGen($genCls, $wireInt0);
        self::assertSame(20, $rtInt0->getChoiceCase());
        self::assertSame(0, $rtInt0->oneofInt);
        self::assertNull($rtInt0->oneofBool);

        // Tier 2 parity for active zero int oneof
        $pbInt0 = self::unmarshalPb($wireInt0, 'Ed23\\Sample2023');
        self::assertTrue($pbInt0->hasOneofInt());
        self::assertSame(0, $pbInt0->getOneofInt());
        self::assertSame($wireInt0, ProtobufCodec::marshal(self::asMessage($pbInt0), true));

        // Switch branch to explicit false -> clears oneofInt
        $m->setOneofBool(false);
        self::assertSame(21, $m->getChoiceCase());
        self::assertNull($m->oneofInt);
        self::assertFalse($m->oneofBool);
        $wireBoolFalse = self::asGen($m)->marshalOmnibuf(true);
        self::assertStringContainsString(Wire::encodeTag(21, WireType::BOOL_FALSE), $wireBoolFalse);
        $rtBool = self::unmarshalGen($genCls, $wireBoolFalse);
        self::assertSame(21, $rtBool->getChoiceCase());
        self::assertNull($rtBool->oneofInt);
        self::assertFalse($rtBool->oneofBool);

        $pbBool = self::unmarshalPb($wireBoolFalse, 'Ed23\\Sample2023');
        self::assertTrue($pbBool->hasOneofBool());
        self::assertFalse($pbBool->getOneofBool());
        self::assertFalse($pbBool->hasOneofInt());
        self::assertSame($wireBoolFalse, ProtobufCodec::marshal(self::asMessage($pbBool), true));

        // Switch branch to empty string -> clears oneofBool
        $m->setOneofStr('');
        self::assertSame(22, $m->getChoiceCase());
        self::assertNull($m->oneofBool);
        self::assertSame('', $m->oneofStr);
        $wireStrEmpty = self::asGen($m)->marshalOmnibuf(true);
        $rtStr = self::unmarshalGen($genCls, $wireStrEmpty);
        self::assertSame(22, $rtStr->getChoiceCase());
        self::assertSame('', $rtStr->oneofStr);
        self::assertNull($rtStr->oneofBool);

        $pbStr = self::unmarshalPb($wireStrEmpty, 'Ed23\\Sample2023');
        self::assertTrue($pbStr->hasOneofStr());
        self::assertSame('', $pbStr->getOneofStr());
        self::assertSame($wireStrEmpty, ProtobufCodec::marshal(self::asMessage($pbStr), true));

        // Switch branch to submessage then clearChoice()
        $m->setOneofChild(self::make($childCls, id: 55, note: 'branch'));
        self::assertSame(23, $m->getChoiceCase());
        self::assertNull($m->oneofStr);
        $wireChild = self::asGen($m)->marshalOmnibuf(true);
        $rtChild = self::unmarshalGen($genCls, $wireChild);
        self::assertSame(23, $rtChild->getChoiceCase());
        self::assertSame(55, $rtChild->oneofChild->id);
        self::assertSame($wireChild, ProtobufCodec::marshal(ProtobufCodec::unmarshal($wireChild, $pbCls), true));

        $m->clearChoice();
        self::assertSame(0, $m->getChoiceCase());
        self::assertNull($m->oneofChild);
        self::assertSame('', self::asGen($m)->marshalOmnibuf(true));

        // Wire with multiple siblings: last valid sibling wins, mismatched wire type does NOT clear active sibling
        $enc = new Encoder();
        $enc->writeSint32Field(20, 123);
        $enc->writeStringField(22, '');
        $enc->writeStringField(20, 'wrong-wire-type-for-int');
        $multiWire = $enc->finish();
        $decodedMulti = self::unmarshalGen($genCls, $multiWire);
        self::assertSame(22, $decodedMulti->getChoiceCase());
        self::assertNull($decodedMulti->oneofInt);
        self::assertSame('', $decodedMulti->oneofStr);

        // Interleaved submessage -> scalar -> submessage must NOT merge first submessage into second
        $c1 = self::asGen(self::make($childCls, id: 1, note: 'first'))->marshalOmnibuf(true);
        $c2 = self::asGen(self::make($childCls, id: 2))->marshalOmnibuf(true);
        $enc2 = new Encoder();
        $enc2->writeTag(23, WireType::SUB_MSG);
        $enc2->beginContainer();
        $enc2->writeRaw($c1);
        $enc2->endContainer();
        $enc2->writeSint32Field(20, 9);
        $enc2->writeTag(23, WireType::SUB_MSG);
        $enc2->beginContainer();
        $enc2->writeRaw($c2);
        $enc2->endContainer();
        $interleaved = $enc2->finish();
        $decodedInterleaved = self::unmarshalGen($genCls, $interleaved);
        self::assertSame(23, $decodedInterleaved->getChoiceCase());
        self::assertNotNull($decodedInterleaved->oneofChild);
        self::assertSame(2, $decodedInterleaved->oneofChild->id);
        self::assertNull($decodedInterleaved->oneofChild->note);
    }

    public function testEdition2023OpaqueViewsPresenceAndOneof(): void
    {
        $genCls = 'EditionsGen\\Ed23\\Sample2023';
        $childCls = 'EditionsGen\\Ed23\\Child';
        $opaqueCls = 'EditionsGen\\Ed23\\OpaqueSample2023';

        // 1. Reading absent (null) vs present-zero on Opaque view
        $opEmpty = self::make($opaqueCls, '');
        self::assertNull($opEmpty->getExpInt());
        self::assertNull($opEmpty->getExpBool());
        self::assertNull($opEmpty->getExpStr());
        self::assertNull($opEmpty->getExpEnum());
        self::assertSame(0, $opEmpty->getImpInt());
        self::assertSame(0, $opEmpty->getChoiceCase());
        self::assertNull($opEmpty->getOneofInt());

        // Mismatched wire type on explicit-presence scalar must return null (not treated as present)
        $badTypeEnc = new Encoder();
        $badTypeEnc->writeStringField(1, 'not-an-int');
        $badTypeEnc->writeUint32Field(4, 1);
        $opMismatch = self::make($opaqueCls, $badTypeEnc->finish());
        self::assertNull($opMismatch->getExpInt());
        self::assertNull($opMismatch->getExpBool());

        // Present zeros return 0 / false / '' (NOT null)
        $zeros = self::make($genCls, expInt: 0, expBool: false, expStr: '', expEnum: 0);
        $opZeros = self::make($opaqueCls, self::asGen($zeros)->marshalOmnibuf(true));
        self::assertSame(0, $opZeros->getExpInt());
        self::assertFalse($opZeros->getExpBool());
        self::assertSame('', $opZeros->getExpStr());
        self::assertSame(0, $opZeros->getExpEnum());

        // 2. Mutating absent -> explicit zero/false/empty writes fields on splice
        $opMut = self::make($opaqueCls, '');
        $opMut->setExpInt(0);
        $opMut->setExpBool(false);
        $opMut->setExpStr('');
        $splicedZeros = (string) $opMut->marshal();
        $decoded = self::unmarshalGen($genCls, $splicedZeros);
        self::assertSame(0, $decoded->expInt);
        self::assertFalse($decoded->expBool);
        self::assertSame('', $decoded->expStr);
        self::assertNull($decoded->expEnum);

        // 3. Mutating present -> null deletes field from spliced wire
        $opDel = self::make($opaqueCls, $splicedZeros);
        $opDel->setExpInt(null);
        $opDel->setExpBool(null);
        $opDel->setExpStr(null);
        self::assertNull($opDel->getExpInt());
        self::assertNull($opDel->getExpBool());
        self::assertNull($opDel->getExpStr());
        self::assertSame('', $opDel->marshal());

        // 4. Opaque oneof: last-winning sibling on wire + switching branches clears sibling
        $enc = new Encoder();
        $enc->writeSint32Field(20, 77);
        $enc->writeStringField(22, '');
        $opOneof = self::make($opaqueCls, $enc->finish());
        self::assertSame(22, $opOneof->getChoiceCase());
        self::assertNull($opOneof->getOneofInt());
        self::assertSame('', $opOneof->getOneofStr());

        // Interleaved submessage on Opaque view
        $c1 = self::asGen(self::make($childCls, id: 1, note: 'old'))->marshalOmnibuf(true);
        $c2 = self::asGen(self::make($childCls, id: 2))->marshalOmnibuf(true);
        $enc2 = new Encoder();
        $enc2->writeTag(23, WireType::SUB_MSG);
        $enc2->beginContainer();
        $enc2->writeRaw($c1);
        $enc2->endContainer();
        $enc2->writeSint32Field(20, 5);
        $enc2->writeTag(23, WireType::SUB_MSG);
        $enc2->beginContainer();
        $enc2->writeRaw($c2);
        $enc2->endContainer();
        $opInter = self::make($opaqueCls, $enc2->finish());
        self::assertSame(23, $opInter->getChoiceCase());
        self::assertNotNull($opInter->getOneofChild());
        self::assertSame(2, $opInter->getOneofChild()->id);
        self::assertNull($opInter->getOneofChild()->note);
        self::assertNotNull($opInter->opaqueOneofChild());
        self::assertSame(2, $opInter->opaqueOneofChild()->getId());
        self::assertNull($opInter->opaqueOneofChild()->getNote());

        // Switch branch on Opaque view from oneof_child -> oneof_bool(false)
        $opInter->setOneofBool(false);
        self::assertSame(21, $opInter->getChoiceCase());
        self::assertNull($opInter->getOneofChild());
        self::assertNull($opInter->opaqueOneofChild());
        self::assertFalse($opInter->getOneofBool());
        $splicedOneof = (string) $opInter->marshal();
        $rtOneof = self::unmarshalGen($genCls, $splicedOneof);
        self::assertSame(21, $rtOneof->getChoiceCase());
        self::assertFalse($rtOneof->oneofBool);
        self::assertNull($rtOneof->oneofInt);
        self::assertNull($rtOneof->oneofChild);

        // clearChoice() deletes active branch from spliced wire
        $opClear = self::make($opaqueCls, $splicedOneof);
        $opClear->clearChoice();
        self::assertSame(0, $opClear->getChoiceCase());
        self::assertNull($opClear->getOneofBool());
        self::assertSame('', $opClear->marshal());
    }

    public function testEdition2023DelimitedMessagesAndOptionExtensions(): void
    {
        $outerCls = 'EditionsGen\\Ed23Delim\\DelimContainer';
        $opaqueCls = 'EditionsGen\\Ed23Delim\\OpaqueDelimContainer';
        $itemCls = 'EditionsGen\\Ed23Delim\\DelimItem';

        $msg = self::make(
            $outerCls,
            singleDelim: self::make($itemCls, code: 0, name: 'delim-single'),
            repDelim: [
                self::make($itemCls, code: 1, name: 'r1'),
                self::make($itemCls, code: 2, name: 'r2'),
            ],
            oneofDelim: self::make($itemCls, code: 3, name: 'oneof-delim'),
        );
        $wire = self::asGen($msg)->marshalOmnibuf(true);
        $rt = self::unmarshalGen($outerCls, $wire);
        self::assertNotNull($rt->singleDelim);
        self::assertSame(0, $rt->singleDelim->code);
        self::assertSame('delim-single', $rt->singleDelim->name);
        self::assertCount(2, $rt->repDelim);
        self::assertSame('r2', $rt->repDelim[1]->name);
        self::assertSame(3, $rt->getPickCase());
        self::assertNotNull($rt->oneofDelim);
        self::assertSame('oneof-delim', $rt->oneofDelim->name);

        // Opaque view over DELIMITED fields
        $op = self::make($opaqueCls, $wire);
        self::assertNotNull($op->opaqueSingleDelim());
        self::assertSame('delim-single', $op->opaqueSingleDelim()->getName());
        self::assertCount(2, $op->getRepDelim());
        self::assertNotNull($op->opaqueOneofDelim());
        self::assertSame('oneof-delim', $op->opaqueOneofDelim()->getName());

        // Synthetic TYPE_GROUP in Editions must also be accepted as WireSubMsg
        $fdp = new FileDescriptorProto();
        $fdp->setName('ed_group.proto');
        $fdp->setPackage('ed.grp');
        $fdp->setSyntax('editions');
        $fdp->setEdition(Edition::EDITION_2023);

        $sub = new DescriptorProto();
        $sub->setName('Inner');
        $sf = new FieldDescriptorProto();
        $sf->setName('v');
        $sf->setNumber(1);
        $sf->setLabel(Label::LABEL_OPTIONAL);
        $sf->setType(Type::TYPE_INT32);
        $sub->setField([$sf]);

        $outer = new DescriptorProto();
        $outer->setName('Outer');
        $gf = new FieldDescriptorProto();
        $gf->setName('inner');
        $gf->setNumber(1);
        $gf->setLabel(Label::LABEL_OPTIONAL);
        $gf->setType(Type::TYPE_GROUP);
        $gf->setTypeName('.ed.grp.Inner');
        $outer->setField([$gf]);

        $fdp->setMessageType([$sub, $outer]);

        $gen = new Generator();
        $resp = $this->parseCodeGenResponse($gen->run($this->buildCodeGenRequest([$fdp], ['ed_group.proto'])));
        self::assertNull($resp['error']);
        self::assertCount(4, $resp['files']);
    }

    public function testEdition2024InheritedFeatureOverridesAndTier2Parity(): void
    {
        $rootCls = 'EditionsGen\\Ed24\\InheritRoot';
        $childCls = 'EditionsGen\\Ed24\\InheritRoot\\NestedChild';
        $pbRootCls = self::pbClass('Ed24\\InheritRoot');
        $pbChildCls = 'Ed24\\InheritRoot\\NestedChild';

        // File-level IMPLICIT -> fileImplicit is int=0, kind is int=0; field explicitKind is ?int=null
        $root = self::make(
            $rootCls,
            fileImplicit: 0,
            kind: 0,
            explicitKind: 0,
            child: self::make(
                $childCls,
                fieldExplicit: 0,
                fieldImplicit: 0,
                bBool: false,
            ),
        );
        self::assertSame(0, $root->fileImplicit);
        self::assertSame(0, $root->kind);
        self::assertSame(0, $root->explicitKind);
        self::assertNotNull($root->child);
        self::assertSame(0, $root->child->fieldExplicit);
        self::assertSame(0, $root->child->fieldImplicit);
        self::assertSame(4, $root->child->getBranchCase());

        $wire = self::asGen($root)->marshalOmnibuf(true);
        // file_implicit (#1) and kind (#3) must be omitted (IMPLICIT zero), while explicit_kind (#4) is emitted
        $rt = self::unmarshalGen($rootCls, $wire);
        self::assertSame(0, $rt->fileImplicit);
        self::assertSame(0, $rt->kind);
        self::assertSame(0, $rt->explicitKind);
        self::assertNotNull($rt->child);
        self::assertSame(0, $rt->child->fieldExplicit);
        self::assertSame(0, $rt->child->fieldImplicit);
        self::assertFalse($rt->child->bBool);
        self::assertSame($wire, self::asGen($rt)->marshalOmnibuf(true));

        // Tier 2 ProtobufCodec byte-for-byte parity
        $pb = self::make($pbRootCls);
        $pb->setFileImplicit(0);
        $pb->setKind(0);
        $pb->setExplicitKind(0);
        $pbChild = self::make($pbChildCls);
        $pbChild->setFieldExplicit(0);
        $pbChild->setFieldImplicit(0);
        $pbChild->setBBool(false);
        $pb->setChild($pbChild);

        $wirePb = ProtobufCodec::marshal(self::asMessage($pb), true);
        self::assertSame($wire, $wirePb);

        $pbRt = self::unmarshalPb($wire, 'Ed24\\InheritRoot');
        self::assertTrue($pbRt->hasExplicitKind());
        self::assertSame(0, $pbRt->getExplicitKind());
        self::assertTrue($pbRt->getChild()->hasFieldExplicit());
        self::assertSame(0, $pbRt->getChild()->getFieldExplicit());
        self::assertTrue($pbRt->getChild()->hasBBool());
        self::assertFalse($pbRt->getChild()->getBBool());
        self::assertSame($wire, ProtobufCodec::marshal(self::asMessage($pbRt), true));

        // Direct multi-level descriptor inheritance: file IMPLICIT -> message EXPLICIT -> oneof -> field IMPLICIT
        $fdp = new FileDescriptorProto();
        $fdp->setName('inherit_chain.proto');
        $fdp->setPackage('chain');
        $fdp->setSyntax('editions');
        $fdp->setEdition(Edition::EDITION_2024);
        $fileOpts = new FileOptions();
        $fileFeat = new FeatureSet();
        $fileFeat->setFieldPresence(FieldPresence::IMPLICIT);
        $fileOpts->setFeatures($fileFeat);
        $fdp->setOptions($fileOpts);

        $msg = new DescriptorProto();
        $msg->setName('Box');
        $msgOpts = new MessageOptions();
        $msgFeat = new FeatureSet();
        $msgFeat->setFieldPresence(FieldPresence::EXPLICIT);
        $msgOpts->setFeatures($msgFeat);
        $msg->setOptions($msgOpts);

        $oneofDecl = new OneofDescriptorProto();
        $oneofDecl->setName('slot');
        $oneofOpts = new OneofOptions();
        $oneofFeat = new FeatureSet();
        $oneofFeat->setFieldPresence(FieldPresence::EXPLICIT);
        $oneofOpts->setFeatures($oneofFeat);
        $oneofDecl->setOptions($oneofOpts);
        $msg->setOneofDecl([$oneofDecl]);

        $f1 = new FieldDescriptorProto();
        $f1->setName('inherited_explicit');
        $f1->setNumber(1);
        $f1->setLabel(Label::LABEL_OPTIONAL);
        $f1->setType(Type::TYPE_INT32);

        $f2 = new FieldDescriptorProto();
        $f2->setName('override_implicit');
        $f2->setNumber(2);
        $f2->setLabel(Label::LABEL_OPTIONAL);
        $f2->setType(Type::TYPE_INT32);
        $f2Opts = new FieldOptions();
        $f2Feat = new FeatureSet();
        $f2Feat->setFieldPresence(FieldPresence::IMPLICIT);
        $f2Opts->setFeatures($f2Feat);
        $f2->setOptions($f2Opts);

        $f3 = new FieldDescriptorProto();
        $f3->setName('oneof_item');
        $f3->setNumber(3);
        $f3->setLabel(Label::LABEL_OPTIONAL);
        $f3->setType(Type::TYPE_BOOL);
        $f3->setOneofIndex(0);

        $msg->setField([$f1, $f2, $f3]);
        $fdp->setMessageType([$msg]);

        $gen = new Generator();
        $resp = $this->parseCodeGenResponse($gen->run($this->buildCodeGenRequest([$fdp], ['inherit_chain.proto'])));
        self::assertNull($resp['error']);
        self::assertStringContainsString('public ?int $inheritedExplicit = null;', $resp['files'][0]);
        self::assertStringContainsString('public int $overrideImplicit = 0;', $resp['files'][0]);
        self::assertStringContainsString('public ?bool $oneofItem = null;', $resp['files'][0]);
    }

    public function testEdition2026StrictVisibilityLimitsAndTier2Parity(): void
    {
        $recCls = 'EditionsGen\\Ed26\\Record2026';
        $metaCls = 'EditionsGen\\Ed26\\Record2026\\LocalMeta';
        $pbRecCls = self::pbClass('Ed26\\Record2026');
        $pbMetaCls = 'Ed26\\Record2026\\LocalMeta';

        $rec = self::make(
            $recCls,
            id: 0,
            enabled: false,
            label: '',
            mode: 999,
            meta: self::make($metaCls, tag: ''),
            num: 0,
        );
        $wire = self::asGen($rec)->marshalOmnibuf(true);

        $rt = self::unmarshalGen($recCls, $wire);
        self::assertSame(0, $rt->id);
        self::assertFalse($rt->enabled);
        self::assertSame('', $rt->label);
        self::assertSame(999, $rt->mode);
        self::assertNotNull($rt->meta);
        self::assertSame('', $rt->meta->tag);
        self::assertSame(6, $rt->getPayloadCase());
        self::assertSame(0, $rt->num);
        self::assertNull($rt->text);

        $pb = self::make($pbRecCls);
        $pb->setId(0);
        $pb->setEnabled(false);
        $pb->setLabel('');
        $pb->setMode(999);
        $pb->setMeta(self::make($pbMetaCls, ['tag' => '']));
        $pb->setNum(0);

        $wirePb = ProtobufCodec::marshal(self::asMessage($pb), true);
        self::assertSame($wire, $wirePb);

        $pbRt = self::unmarshalPb($wire, 'Ed26\\Record2026');
        self::assertTrue($pbRt->hasId());
        self::assertSame(0, $pbRt->getId());
        self::assertTrue($pbRt->hasEnabled());
        self::assertFalse($pbRt->getEnabled());
        self::assertTrue($pbRt->hasMode());
        self::assertSame(999, $pbRt->getMode());
        self::assertTrue($pbRt->hasNum());
        self::assertSame(0, $pbRt->getNum());
        self::assertSame($wire, ProtobufCodec::marshal(self::asMessage($pbRt), true));
    }

    public function testPluginRejectsUnsupportedEditions(): void
    {
        $gen = new Generator();

        foreach ([0, 900, 1003, 9999] as $badEdition) {
            $fdp = new FileDescriptorProto();
            $fdp->setName("ed_{$badEdition}.proto");
            $fdp->setPackage('bad.ed');
            $fdp->setSyntax('editions');
            if ($badEdition !== 0) {
                $fdp->setEdition($badEdition);
            }
            $resp = $this->parseCodeGenResponse($gen->run($this->buildCodeGenRequest([$fdp], ["ed_{$badEdition}.proto"])));
            self::assertNotNull($resp['error'], "Edition {$badEdition} should be rejected");
            self::assertStringContainsString('unsupported protobuf edition', strtolower($resp['error']));
            self::assertSame(3, $resp['supported_features']);
            self::assertSame(1000, $resp['minimum_edition']);
            self::assertSame(1002, $resp['maximum_edition']);
        }
    }

    public function testPluginRejectsUnsupportedEditionFeaturesViaRealProtoc(): void
    {
        $negDir = self::$tmpDir . '/neg';
        mkdir($negDir, 0o755, true);

        $cases = [
            'closed_enum' => [
                'files' => [
                    'closed_enum.proto' => <<<'PROTO'
edition = "2023";
package neg.closed;
enum BadClosed {
  option features.enum_type = CLOSED;
  BAD_CLOSED_ZERO = 0;
}
message M { BadClosed e = 1; }
PROTO,
                ],
                'targets' => ['closed_enum.proto'],
                'expect' => 'closed',
            ],
            'cross_file_closed_enum' => [
                'files' => [
                    'dep_closed.proto' => <<<'PROTO'
edition = "2023";
package neg.depclosed;
enum DepClosed {
  option features.enum_type = CLOSED;
  DEP_CLOSED_ZERO = 0;
}
PROTO,
                    'use_closed.proto' => <<<'PROTO'
edition = "2023";
package neg.useclosed;
import "dep_closed.proto";
message M { neg.depclosed.DepClosed e = 1; }
PROTO,
                ],
                'targets' => ['use_closed.proto'],
                'expect' => 'closed',
            ],
            'cross_file_proto2_enum' => [
                'files' => [
                    'dep_p2.proto' => <<<'PROTO'
syntax = "proto2";
package neg.depp2;
enum P2Enum { P2_ZERO = 0; }
PROTO,
                    'use_p2_enum.proto' => <<<'PROTO'
edition = "2023";
package neg.usep2;
import "dep_p2.proto";
message M { neg.depp2.P2Enum e = 1; }
PROTO,
                ],
                'targets' => ['use_p2_enum.proto'],
                'expect' => 'closed',
            ],
            'legacy_required' => [
                'files' => [
                    'req.proto' => <<<'PROTO'
edition = "2023";
package neg.req;
message M { int32 x = 1 [features.field_presence = LEGACY_REQUIRED]; }
PROTO,
                ],
                'targets' => ['req.proto'],
                'expect' => 'legacy_required',
            ],
            'utf8_none_scalar' => [
                'files' => [
                    'utf8_scalar.proto' => <<<'PROTO'
edition = "2023";
package neg.u1;
message M { string s = 1 [features.utf8_validation = NONE]; }
PROTO,
                ],
                'targets' => ['utf8_scalar.proto'],
                'expect' => 'utf8_validation',
            ],
            'utf8_none_repeated' => [
                'files' => [
                    'utf8_rep.proto' => <<<'PROTO'
edition = "2023";
package neg.u2;
message M { repeated string s = 1 [features.utf8_validation = NONE]; }
PROTO,
                ],
                'targets' => ['utf8_rep.proto'],
                'expect' => 'utf8_validation',
            ],
            'utf8_none_map_key' => [
                'files' => [
                    'utf8_map_k.proto' => <<<'PROTO'
edition = "2023";
package neg.u3;
option features.utf8_validation = NONE;
message M { map<string, int32> m = 1; }
PROTO,
                ],
                'targets' => ['utf8_map_k.proto'],
                'expect' => 'utf8_validation',
            ],
            'utf8_none_map_val' => [
                'files' => [
                    'utf8_map_v.proto' => <<<'PROTO'
edition = "2023";
package neg.u4;
option features.utf8_validation = NONE;
message M { map<int32, string> m = 1; }
PROTO,
                ],
                'targets' => ['utf8_map_v.proto'],
                'expect' => 'utf8_validation',
            ],
            'custom_default_int' => [
                'files' => [
                    'def_int.proto' => <<<'PROTO'
edition = "2023";
package neg.d1;
message M { int32 x = 1 [default = 42]; }
PROTO,
                ],
                'targets' => ['def_int.proto'],
                'expect' => 'default',
            ],
            'custom_default_neg_zero' => [
                'files' => [
                    'def_neg0.proto' => <<<'PROTO'
edition = "2023";
package neg.d2;
message M { float f = 1 [default = -0.0]; }
PROTO,
                ],
                'targets' => ['def_neg0.proto'],
                'expect' => 'default',
            ],
            'custom_default_enum' => [
                'files' => [
                    'def_enum.proto' => <<<'PROTO'
edition = "2023";
package neg.d3;
enum E { E_ZERO = 0; E_ONE = 1; }
message M { E e = 1 [default = E_ONE]; }
PROTO,
                ],
                'targets' => ['def_enum.proto'],
                'expect' => 'default',
            ],
            'non_option_extension' => [
                'files' => [
                    'ext.proto' => <<<'PROTO'
edition = "2023";
package neg.ext;
message Extensible { extensions 100 to 199; }
extend Extensible { int32 extra = 100; }
PROTO,
                ],
                'targets' => ['ext.proto'],
                'expect' => 'extension',
            ],
            'proto2_group' => [
                'files' => [
                    'p2group.proto' => <<<'PROTO'
syntax = "proto2";
package neg.grp;
message M {
  optional group MyGrp = 1 {
    optional int32 a = 2;
  }
}
PROTO,
                ],
                'targets' => ['p2group.proto'],
                'expect' => 'group',
            ],
        ];

        $pluginBin = realpath(__DIR__ . '/../bin/protoc-gen-omnibuf-php');
        self::assertIsString($pluginBin);

        foreach ($cases as $name => $case) {
            $caseDir = $negDir . '/' . $name;
            $outDir = $caseDir . '/out';
            mkdir($outDir, 0o755, true);
            foreach ($case['files'] as $fname => $contents) {
                file_put_contents($caseDir . '/' . $fname, $contents);
            }

            $cmd = [
                self::$protocBin,
                '-I',
                $caseDir,
                "--plugin=protoc-gen-omnibuf-php={$pluginBin}",
                "--omnibuf-php_out={$outDir}",
            ];
            foreach ($case['targets'] as $t) {
                $cmd[] = $caseDir . '/' . $t;
            }

            [$exitCode, $output] = self::execCommand($cmd);
            self::assertNotSame(0, $exitCode, "Case '{$name}' should have failed protoc plugin generation");
            self::assertStringContainsString(
                $case['expect'],
                strtolower($output),
                "Case '{$name}' output did not contain expected substring '{$case['expect']}': {$output}",
            );
        }
    }

    public function testProtobufCodecRejectsUnsupportedEditionFeatures(): void
    {
        $badClasses = [
            'utf8_scalar' => [self::pbClass('NegUtf8Scalar\\BadUtf8Scalar'), 'utf8_validation'],
            'utf8_map' => [self::pbClass('NegUtf8Map\\BadUtf8Map'), 'utf8_validation'],
            'custom_default' => [self::pbClass('NegCustomDef\\BadCustomDefault'), 'default'],
        ];

        foreach ($badClasses as $label => [$cls, $expectedSubstr]) {
            $msg = self::asMessage(self::make($cls));
            try {
                OmniBuf::marshal($msg);
                self::fail("ProtobufCodec::marshal should reject {$label}");
            } catch (EncodeException $e) {
                self::assertStringContainsString($expectedSubstr, strtolower($e->getMessage()));
            }

            try {
                OmniBuf::unmarshal('', $cls);
                self::fail("ProtobufCodec::unmarshal should reject {$label}");
            } catch (DecodeException $e) {
                self::assertStringContainsString($expectedSubstr, strtolower($e->getMessage()));
            }
        }

        // Verify decodePhpStringLiteral handles hex, octal, dollar, quotes, and escapes accurately
        self::assertSame(
            "\x0a\x00\"\$\\\n\r\t",
            ProtobufEditionMetadata::decodePhpStringLiteral('"\\x0a\\0\\"\\$\\\\\\n\\r\\t"'),
        );
    }

    public function testEditionResolverDirectValidationEdgeCases(): void
    {
        // Field with LEGACY_REQUIRED feature on FileDescriptorProto
        $fdp = new FileDescriptorProto();
        $fdp->setName('req_direct.proto');
        $fdp->setPackage('direct.req');
        $fdp->setSyntax('editions');
        $fdp->setEdition(Edition::EDITION_2024);

        $msg = new DescriptorProto();
        $msg->setName('ReqMsg');
        $f = new FieldDescriptorProto();
        $f->setName('must_set');
        $f->setNumber(1);
        $f->setLabel(Label::LABEL_OPTIONAL);
        $f->setType(Type::TYPE_INT32);
        $fOpts = new FieldOptions();
        $feat = new FeatureSet();
        $feat->setFieldPresence(FieldPresence::LEGACY_REQUIRED);
        $fOpts->setFeatures($feat);
        $f->setOptions($fOpts);
        $msg->setField([$f]);
        $fdp->setMessageType([$msg]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LEGACY_REQUIRED');
        EditionResolver::validateFile($fdp, []);
    }

    private static function make(string $className, mixed ...$args): mixed
    {
        return new $className(...$args);
    }

    private static function unmarshalGen(string $className, string $data): mixed
    {
        return $className::unmarshalOmnibuf($data);
    }

    private static function unmarshalPb(string $data, string $className): mixed
    {
        return ProtobufCodec::unmarshal($data, self::pbClass($className));
    }

    private static function asGen(mixed $obj): GeneratedMessage
    {
        self::assertInstanceOf(GeneratedMessage::class, $obj);
        return $obj;
    }

    private static function asMessage(mixed $obj): Message
    {
        self::assertInstanceOf(Message::class, $obj);
        return $obj;
    }

    /**
     * @return class-string<Message>
     */
    private static function pbClass(string $className): string
    {
        self::assertTrue(class_exists($className), "Generated class {$className} does not exist");
        self::assertTrue(is_subclass_of($className, Message::class), "{$className} must extend Message");
        return $className;
    }

    private static function writeProtoFixtures(): void
    {
        $p = self::$tmpDir . '/proto';

        file_put_contents($p . '/ed2023.proto', <<<'PROTO'
edition = "2023";
package ed23;

enum Status {
  STATUS_UNSPECIFIED = 0;
  STATUS_OK = 1;
  STATUS_ERR = 2;
}

message Child {
  int32 id = 1;
  string note = 2;
}

message Sample2023 {
  int32 exp_int = 1;
  uint32 exp_uint = 2;
  int64 exp_int64 = 3;
  bool exp_bool = 4;
  float exp_float = 5;
  double exp_double = 6;
  string exp_str = 7;
  bytes exp_bytes = 8;
  Status exp_enum = 9;
  int32 zero_def = 10 [default = 0];

  int32 imp_int = 11 [features.field_presence = IMPLICIT];
  bool imp_bool = 12 [features.field_presence = IMPLICIT];
  string imp_str = 13 [features.field_presence = IMPLICIT];

  Child child = 14;
  repeated int32 rep_packed = 15 [features.repeated_field_encoding = PACKED];
  repeated int32 rep_expanded = 16 [features.repeated_field_encoding = EXPANDED];
  repeated Child rep_children = 17;

  map<string, int32> scores = 18;
  map<string, Child> child_map = 19;

  oneof choice {
    int32 oneof_int = 20;
    bool oneof_bool = 21;
    string oneof_str = 22;
    Child oneof_child = 23;
  }
}
PROTO);

        file_put_contents($p . '/ed2023_delim.proto', <<<'PROTO'
edition = "2023";
package ed23_delim;

import "google/protobuf/descriptor.proto";

extend google.protobuf.FieldOptions {
  string custom_note = 50001;
}

message DelimItem {
  int32 code = 1 [(custom_note) = "item-code"];
  string name = 2;
}

message DelimContainer {
  DelimItem single_delim = 1 [features.message_encoding = DELIMITED];
  repeated DelimItem rep_delim = 2 [features.message_encoding = DELIMITED];
  oneof pick {
    DelimItem oneof_delim = 3 [features.message_encoding = DELIMITED];
    int32 oneof_code = 4;
  }
}
PROTO);

        file_put_contents($p . '/ed2024.proto', <<<'PROTO'
edition = "2024";
package ed24;

option features.field_presence = IMPLICIT;
option features.enforce_naming_style = STYLE2024;
option features.default_symbol_visibility = EXPORT_TOP_LEVEL;
option features.json_format = LEGACY_BEST_EFFORT;

export enum Kind {
  KIND_UNSPECIFIED = 0;
  KIND_ALPHA = 1;
}

export message InheritRoot {
  int32 file_implicit = 1;

  message NestedChild {
    option features.json_format = ALLOW;
    int32 field_explicit = 1 [features.field_presence = EXPLICIT];
    int32 field_implicit = 2 [features.field_presence = IMPLICIT];

    oneof branch {
      option features.enforce_naming_style = STYLE_LEGACY;
      int32 b_int = 3;
      bool b_bool = 4;
    }
  }

  NestedChild child = 2;
  Kind kind = 3;
  Kind explicit_kind = 4 [features.field_presence = EXPLICIT];
}
PROTO);

        file_put_contents($p . '/ed2026.proto', <<<'PROTO'
edition = "2026";
package ed26;

option features.default_symbol_visibility = STRICT;
option features.enforce_naming_style = STYLE2026;

export enum Mode {
  MODE_UNSPECIFIED = 0;
  MODE_FAST = 1;
}

export message Record2026 {
  option features.enforce_proto_limits = PROTO_LIMITS2026;

  message LocalMeta {
    string tag = 1;
  }

  int32 id = 1;
  bool enabled = 2;
  string label = 3 [features.field_presence = IMPLICIT];
  Mode mode = 4;
  LocalMeta meta = 5;

  oneof payload {
    int32 num = 6;
    string text = 7;
  }
}
PROTO);

        file_put_contents($p . '/bad_utf8_scalar.proto', <<<'PROTO'
edition = "2023";
package neg.utf8scalar;
option php_namespace = "NegUtf8Scalar";
message BadUtf8Scalar {
  string s = 1 [features.utf8_validation = NONE];
}
PROTO);

        file_put_contents($p . '/bad_utf8_map.proto', <<<'PROTO'
edition = "2023";
package neg.utf8map;
option php_namespace = "NegUtf8Map";
option features.utf8_validation = NONE;
message BadUtf8Map {
  map<string, int32> m = 1;
}
PROTO);

        file_put_contents($p . '/bad_default.proto', <<<'PROTO'
edition = "2023";
package neg.customdef;
option php_namespace = "NegCustomDef";
message BadCustomDefault {
  int32 x = 1 [default = 42];
}
PROTO);
    }

    /**
     * @param list<string> $protoFiles
     */
    private static function runProtoc(
        array $protoFiles,
        bool $emitOmnibuf,
        bool $emitPhp,
        string $namespacePrefix,
    ): void {
        $cmd = [self::$protocBin, '-I', self::$tmpDir . '/proto'];
        if ($emitOmnibuf) {
            $pluginBin = realpath(__DIR__ . '/../bin/protoc-gen-omnibuf-php');
            self::assertIsString($pluginBin);
            $cmd[] = "--plugin=protoc-gen-omnibuf-php={$pluginBin}";
            $outArg = $namespacePrefix !== ''
                ? "namespace_prefix={$namespacePrefix}:" . self::$tmpDir . '/gen'
                : self::$tmpDir . '/gen';
            $cmd[] = "--omnibuf-php_out={$outArg}";
        }
        if ($emitPhp) {
            $cmd[] = '--php_out=' . self::$tmpDir . '/php';
        }
        foreach ($protoFiles as $f) {
            $cmd[] = self::$tmpDir . '/proto/' . $f;
        }
        [$exitCode, $output] = self::execCommand($cmd);
        if ($exitCode !== 0) {
            self::fail('protoc failed (' . implode(' ', $cmd) . '): ' . $output);
        }
    }

    private static function locateProtoc(): string
    {
        $candidates = [];
        $envPath = getenv('PATH');
        if (\is_string($envPath) && $envPath !== '') {
            foreach (explode(PATH_SEPARATOR, $envPath) as $dir) {
                $candidates[] = rtrim($dir, '/') . '/protoc';
            }
        }
        $home = getenv('HOME');
        if (\is_string($home) && $home !== '') {
            $candidates[] = $home . '/.local/share/mise/shims/protoc';
        }
        $candidates[] = '/opt/homebrew/bin/protoc';
        $candidates[] = '/usr/local/bin/protoc';

        foreach ($candidates as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        self::markTestSkipped('protoc binary not found');
    }

    /**
     * @param list<string> $cmd
     * @return array{0: int, 1: string}
     */
    private static function execCommand(array $cmd): array
    {
        $descSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descSpec, $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('Failed to spawn process: ' . implode(' ', $cmd));
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, trim($stdout . "\n" . $stderr)];
    }

    private static function loadPhpDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        foreach ($files as $f) {
            require_once $f;
        }
    }

    private static function removeDirRecursive(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * @param list<FileDescriptorProto> $files
     * @param list<string> $filesToGenerate
     */
    private function buildCodeGenRequest(array $files, array $filesToGenerate): string
    {
        $req = '';
        foreach ($filesToGenerate as $name) {
            $req .= $this->encodePbString(1, $name);
        }
        foreach ($files as $fdp) {
            $req .= $this->encodePbString(15, $fdp->serializeToString());
        }
        return $req;
    }

    /**
     * @return array{error: ?string, supported_features: int, minimum_edition: int, maximum_edition: int, files: list<string>}
     */
    private function parseCodeGenResponse(string $buf): array
    {
        $error = null;
        $supported = 0;
        $minEd = 0;
        $maxEd = 0;
        $files = [];

        $pos = 0;
        $end = \strlen($buf);
        while ($pos < $end) {
            $tag = $this->readPbVarint($buf, $pos, $end);
            $fieldNum = $tag >> 3;
            $wt = $tag & 7;
            if ($wt === 0) {
                $v = $this->readPbVarint($buf, $pos, $end);
                if ($fieldNum === 2) {
                    $supported = $v;
                } elseif ($fieldNum === 3) {
                    $minEd = $v;
                } elseif ($fieldNum === 4) {
                    $maxEd = $v;
                }
            } elseif ($wt === 2) {
                $len = $this->readPbVarint($buf, $pos, $end);
                $slice = substr($buf, $pos, $len);
                $pos += $len;
                if ($fieldNum === 1) {
                    $error = $slice;
                } elseif ($fieldNum === 15) {
                    $files[] = $slice;
                }
            } else {
                self::fail("Unexpected protobuf wire type {$wt} in CodeGeneratorResponse");
            }
        }

        return [
            'error' => $error,
            'supported_features' => $supported,
            'minimum_edition' => $minEd,
            'maximum_edition' => $maxEd,
            'files' => $files,
        ];
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

    private function encodePbString(int $fieldNum, string $data): string
    {
        return $this->encodePbVarint(($fieldNum << 3) | 2) . $this->encodePbVarint(\strlen($data)) . $data;
    }

    private function readPbVarint(string $buf, int &$pos, int $end): int
    {
        $result = 0;
        $shift = 0;
        while ($pos < $end) {
            $b = \ord($buf[$pos++]);
            $result |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
        self::fail('Truncated varint in CodeGeneratorResponse');
    }
}
