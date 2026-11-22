<?php

declare(strict_types=1);

namespace OmniBuf;

use Google\Protobuf\Internal\Descriptor;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\FieldDescriptor;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;

/**
 * Tier 2 codec for standard `protoc --php_out` (`Google\Protobuf\Internal\Message`) types.
 */
final class ProtobufCodec
{
    public static function marshal(Message $msg, bool $deterministic = false, bool $selfDescribing = false): string
    {
        $desc = self::descriptorFor($msg::class);
        $enc = new Encoder();
        if ($selfDescribing) {
            self::schemaMetaFor($desc)->writeTo($enc, true);
        }
        self::marshalTo($msg, $desc, $enc, $deterministic);
        return $enc->finish();
    }

    public static function marshalTo(Message $msg, Descriptor $desc, Encoder $enc, bool $deterministic = false, int $depth = 0): void
    {
        if ($depth > Wire::MAX_NESTING_DEPTH) {
            throw new EncodeException("omnibuf: encode nesting depth {$depth} exceeds maximum " . Wire::MAX_NESTING_DEPTH);
        }
        /** @var array<int, FieldDescriptor> $fields */
        $fields = $desc->getField();
        ksort($fields, SORT_NUMERIC);

        foreach ($fields as $num => $fd) {
            $getter = $fd->getGetter();
            $val = $msg->{$getter}();

            if ($fd->isMap()) {
                /** @var iterable<int|string, mixed> $val */
                $entries = [];
                foreach ($val as $k => $v) {
                    $entries[$k] = $v;
                }
                if ($entries === []) {
                    continue;
                }
                $entryDesc = self::resolveMessageDescriptor($fd);
                /** @var FieldDescriptor $keyFd */
                $keyFd = $entryDesc->getFieldByNumber(1);
                /** @var FieldDescriptor $valFd */
                $valFd = $entryDesc->getFieldByNumber(2);
                $keyWt = self::gpbTypeToWireType((int) $keyFd->getType());
                $valWt = self::gpbTypeToWireType((int) $valFd->getType());

                if ($deterministic) {
                    self::sortMapKeys($entries, $keyWt);
                }

                $enc->writeTag($num, WireType::MAP);
                $enc->beginContainer();
                $enc->writeByte($keyWt);
                $enc->writeByte($valWt);
                $enc->writeVarint(\count($entries));
                foreach ($entries as $k => $v) {
                    self::writeCollectionItem($enc, $keyFd, $k, $deterministic, $depth + 1);
                    self::writeCollectionItem($enc, $valFd, $v, $deterministic, $depth + 1);
                }
                $enc->endContainer();
                continue;
            }

            if ($fd->isRepeated()) {
                /** @var iterable<mixed> $val */
                $items = [];
                foreach ($val as $item) {
                    $items[] = $item;
                }
                if ($items === []) {
                    continue;
                }
                $elemWt = self::gpbTypeToWireType((int) $fd->getType());
                $enc->writeTag($num, WireType::ARRAY);
                $enc->beginContainer();
                $enc->writeByte($elemWt);
                $enc->writeVarint(\count($items));
                foreach ($items as $item) {
                    self::writeCollectionItem($enc, $fd, $item, $deterministic, $depth + 1);
                }
                $enc->endContainer();
                continue;
            }

            $gpbType = (int) $fd->getType();
            if ($gpbType === GPBType::MESSAGE) {
                if (!$val instanceof Message) {
                    continue;
                }
                $subDesc = self::descriptorFor($val::class);
                $enc->writeTag($num, WireType::SUB_MSG);
                $enc->beginContainer();
                self::marshalTo($val, $subDesc, $enc, $deterministic, $depth + 1);
                $enc->endContainer();
                continue;
            }

            if ($val === null) {
                continue;
            }

            // Preserve explicit presence for proto3 optional / oneof fields
            $hasExplicitPresence = $fd->getContainingOneof() !== null;
            if (!$hasExplicitPresence && self::isZeroScalar($gpbType, $val)) {
                continue;
            }
            if ($hasExplicitPresence) {
                $hasser = 'has' . substr((string) $getter, 3);
                if (method_exists($msg, $hasser) && !$msg->{$hasser}()) {
                    continue;
                }
            }

            self::writeScalarField($enc, $num, $gpbType, $val);
        }
    }

    /**
     * @template T of Message
     * @param class-string<T>|T $target
     * @return T
     */
    public static function unmarshal(
        string $data,
        string|Message $target,
        bool $merge = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): Message {
        $msg = \is_string($target) ? new $target() : $target;
        $desc = self::descriptorFor($msg::class);
        $dec = new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements);
        self::decodeInto($dec, $msg, $desc, $merge);
        /** @var T $msg */
        return $msg;
    }

    public static function decodeInto(Decoder $dec, Message $msg, Descriptor $desc, bool $merge = false): void
    {
        if (!$merge) {
            $msg->clear();
        }

        while ($dec->hasMore()) {
            [$fieldNum, $wireType] = $dec->readTag();
            if ($fieldNum === 0) {
                $dec->readField0();
                continue;
            }
            /** @var ?FieldDescriptor $fd */
            $fd = $desc->getFieldByNumber($fieldNum);
            if ($fd === null) {
                $dec->skipUnknown($wireType);
                continue;
            }

            $gpbType = (int) $fd->getType();
            $getter = (string) $fd->getGetter();
            $setter = (string) $fd->getSetter();

            if ($fd->isMap()) {
                if ($wireType !== WireType::MAP) {
                    $dec->skipUnknown($wireType);
                    continue;
                }
                $entryDesc = self::resolveMessageDescriptor($fd);
                /** @var FieldDescriptor $keyFd */
                $keyFd = $entryDesc->getFieldByNumber(1);
                /** @var FieldDescriptor $valFd */
                $valFd = $entryDesc->getFieldByNumber(2);
                $keyWt = self::gpbTypeToWireType((int) $keyFd->getType());
                $valWt = self::gpbTypeToWireType((int) $valFd->getType());

                [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader($keyWt, $valWt);
                $mapField = $msg->{$getter}();
                for ($i = 0; $i < $count; $i++) {
                    $k = self::readCollectionItem($mapDec, $keyFd);
                    $v = self::readCollectionItem($mapDec, $valFd);
                    $mapField[$k] = $v;
                }
                $mapDec->assertAtEnd('WireMap');
                continue;
            }

            if ($fd->isRepeated()) {
                if ($wireType !== WireType::ARRAY) {
                    $dec->skipUnknown($wireType);
                    continue;
                }
                $elemWt = self::gpbTypeToWireType($gpbType);
                [$arrDec, $_et, $count] = $dec->readArrayHeader($elemWt);
                $repField = $msg->{$getter}();
                for ($i = 0; $i < $count; $i++) {
                    $repField[] = self::readCollectionItem($arrDec, $fd);
                }
                $arrDec->assertAtEnd('WireArray');
                continue;
            }

            if ($gpbType === GPBType::BOOL) {
                if ($wireType === WireType::BOOL_FALSE) {
                    $msg->{$setter}(false);
                    continue;
                }
                if ($wireType === WireType::BOOL_TRUE) {
                    $msg->{$setter}(true);
                    continue;
                }
                $dec->skipUnknown($wireType);
                continue;
            }

            $expectedWt = self::gpbTypeToWireType($gpbType);
            if ($wireType !== $expectedWt) {
                $dec->skipUnknown($wireType);
                continue;
            }

            if ($gpbType === GPBType::MESSAGE) {
                $subDec = $dec->readSubMessageDecoder();
                $subDesc = self::resolveMessageDescriptor($fd);
                $subClass = (string) $subDesc->getClass();
                $existing = $msg->{$getter}();
                if ($existing instanceof Message) {
                    self::decodeInto($subDec, $existing, $subDesc, true);
                } else {
                    /** @var Message $child */
                    $child = new $subClass();
                    self::decodeInto($subDec, $child, $subDesc, false);
                    $msg->{$setter}($child);
                }
                continue;
            }

            $msg->{$setter}(self::readScalar($dec, $gpbType));
        }
    }

    /**
     * @param array<string, true> $visited
     */
    public static function schemaMetaFor(Descriptor $desc, array $visited = []): SchemaMeta
    {
        $fullName = (string) $desc->getFullName();
        $visited[$fullName] = true;
        $fields = [];
        $children = [];

        /** @var array<int, FieldDescriptor> $descFields */
        $descFields = $desc->getField();
        foreach ($descFields as $num => $fd) {
            $fields[$num] = (string) $fd->getName();
            if ($fd->isMap()) {
                $entryDesc = self::resolveMessageDescriptor($fd);
                /** @var FieldDescriptor $valFd */
                $valFd = $entryDesc->getFieldByNumber(2);
                if ((int) $valFd->getType() === GPBType::MESSAGE) {
                    $valDesc = self::resolveMessageDescriptor($valFd);
                    $valName = (string) $valDesc->getFullName();
                    if (!isset($visited[$valName])) {
                        $children[$num] = self::schemaMetaFor($valDesc, $visited);
                    }
                }
            } elseif ((int) $fd->getType() === GPBType::MESSAGE) {
                $subDesc = self::resolveMessageDescriptor($fd);
                $subName = (string) $subDesc->getFullName();
                if (!isset($visited[$subName])) {
                    $children[$num] = self::schemaMetaFor($subDesc, $visited);
                }
            }
        }
        return new SchemaMeta($fullName, $fields, $children);
    }

    /**
     * @param class-string<Message> $class
     */
    private static function descriptorFor(string $class): Descriptor
    {
        $pool = DescriptorPool::getGeneratedPool();
        $desc = $pool->getDescriptorByClassName($class);
        if ($desc === null) {
            // Instantiate once to trigger GPBMetadata static registration
            new $class();
            $desc = $pool->getDescriptorByClassName($class);
        }
        if (!$desc instanceof Descriptor) {
            throw new EncodeException("omnibuf: protobuf descriptor not found for class {$class}");
        }
        return $desc;
    }

    private static function resolveMessageDescriptor(FieldDescriptor $fd): Descriptor
    {
        $mt = $fd->getMessageType();
        if ($mt instanceof Descriptor) {
            return $mt;
        }
        return self::descriptorByFullName((string) $mt);
    }

    private static function descriptorByFullName(string $fullName): Descriptor
    {
        $pool = DescriptorPool::getGeneratedPool();
        $desc = $pool->getDescriptorByProtoName($fullName);
        if (!$desc instanceof Descriptor) {
            throw new DecodeException("omnibuf: protobuf descriptor not found for '{$fullName}'");
        }
        return $desc;
    }

    private static function gpbTypeToWireType(int $gpbType): int
    {
        return match ($gpbType) {
            GPBType::UINT32, GPBType::UINT64 => WireType::UINT,
            GPBType::INT32, GPBType::INT64, GPBType::SINT32, GPBType::SINT64, GPBType::ENUM => WireType::SINT,
            GPBType::BOOL => WireType::BOOL_FALSE,
            GPBType::FIXED32 => WireType::FIXED32,
            GPBType::FIXED64 => WireType::FIXED64,
            GPBType::SFIXED32 => WireType::SFIXED32,
            GPBType::SFIXED64 => WireType::SFIXED64,
            GPBType::FLOAT => WireType::FLOAT32,
            GPBType::DOUBLE => WireType::FLOAT64,
            GPBType::STRING => WireType::STRING,
            GPBType::BYTES => WireType::BYTES,
            GPBType::MESSAGE => WireType::SUB_MSG,
            default => throw new EncodeException("omnibuf: unsupported GPBType {$gpbType}"),
        };
    }

    private static function isZeroScalar(int $gpbType, mixed $val): bool
    {
        return match ($gpbType) {
            GPBType::UINT32, GPBType::INT32, GPBType::INT64, GPBType::SINT32, GPBType::SINT64,
            GPBType::FIXED32, GPBType::SFIXED32, GPBType::SFIXED64, GPBType::ENUM => (int) $val === 0,
            GPBType::UINT64, GPBType::FIXED64 => (\is_int($val) ? $val : UInt64::fromDecimalString((string) $val)) === 0,
            GPBType::BOOL => $val === false,
            GPBType::FLOAT => pack('g', (float) $val) === "\x00\x00\x00\x00",
            GPBType::DOUBLE => (float) $val === 0.0 && pack('e', (float) $val) === "\x00\x00\x00\x00\x00\x00\x00\x00",
            GPBType::STRING, GPBType::BYTES => $val === '',
            default => false,
        };
    }

    private static function writeScalarField(Encoder $enc, int $num, int $gpbType, mixed $val): void
    {
        switch ($gpbType) {
            case GPBType::UINT32:
                $enc->writeUint32Field($num, (int) $val);
                return;
            case GPBType::UINT64:
                $enc->writeUintField($num, \is_string($val) ? $val : (int) $val);
                return;
            case GPBType::INT32:
            case GPBType::SINT32:
            case GPBType::ENUM:
                $enc->writeSint32Field($num, (int) $val);
                return;
            case GPBType::INT64:
            case GPBType::SINT64:
                $enc->writeSintField($num, (int) $val);
                return;
            case GPBType::BOOL:
                $enc->writeBoolField($num, (bool) $val);
                return;
            case GPBType::FIXED32:
                $enc->writeFixed32Field($num, (int) $val);
                return;
            case GPBType::FIXED64:
                $enc->writeFixed64Field($num, \is_string($val) ? $val : (int) $val);
                return;
            case GPBType::SFIXED32:
                $enc->writeSfixed32Field($num, (int) $val);
                return;
            case GPBType::SFIXED64:
                $enc->writeSfixed64Field($num, (int) $val);
                return;
            case GPBType::FLOAT:
                $enc->writeFloat32Field($num, (float) $val);
                return;
            case GPBType::DOUBLE:
                $enc->writeFloat64Field($num, (float) $val);
                return;
            case GPBType::STRING:
                $enc->writeStringField($num, (string) $val);
                return;
            case GPBType::BYTES:
                $enc->writeBytesField($num, (string) $val);
                return;
            default:
                throw new EncodeException("omnibuf: unsupported GPBType {$gpbType}");
        }
    }

    private static function writeCollectionItem(Encoder $enc, FieldDescriptor $fd, mixed $val, bool $deterministic, int $depth): void
    {
        $gpbType = (int) $fd->getType();
        switch ($gpbType) {
            case GPBType::UINT32:
                $enc->writeVarint(((int) $val) & 0xFFFFFFFF);
                return;
            case GPBType::UINT64:
                $enc->writeVarint(\is_int($val) ? $val : UInt64::fromDecimalString((string) $val));
                return;
            case GPBType::INT32:
            case GPBType::SINT32:
            case GPBType::ENUM:
                $enc->writeSintRaw((((int) $val) << 32) >> 32);
                return;
            case GPBType::INT64:
            case GPBType::SINT64:
                $enc->writeSintRaw((int) $val);
                return;
            case GPBType::BOOL:
                $enc->writeByte(((bool) $val) ? 1 : 0);
                return;
            case GPBType::FIXED32:
            case GPBType::SFIXED32:
                $enc->writeFixed32Raw((int) $val);
                return;
            case GPBType::FIXED64:
                $enc->writeFixed64Raw(\is_string($val) ? $val : (int) $val);
                return;
            case GPBType::SFIXED64:
                $enc->writeFixed64Raw((int) $val);
                return;
            case GPBType::FLOAT:
                $enc->writeFloat32Raw((float) $val);
                return;
            case GPBType::DOUBLE:
                $enc->writeFloat64Raw((float) $val);
                return;
            case GPBType::STRING:
                $enc->writeRawStringPayload((string) $val);
                return;
            case GPBType::BYTES:
                $enc->writeRawBytesPayload((string) $val);
                return;
            case GPBType::MESSAGE:
                if (!$val instanceof Message) {
                    throw new EncodeException('omnibuf: expected Google\\Protobuf\\Internal\\Message item');
                }
                $subDesc = self::descriptorFor($val::class);
                $enc->beginContainer();
                self::marshalTo($val, $subDesc, $enc, $deterministic, $depth + 1);
                $enc->endContainer();
                return;
            default:
                throw new EncodeException("omnibuf: unsupported GPBType {$gpbType}");
        }
    }

    private static function readScalar(Decoder $dec, int $gpbType): mixed
    {
        return match ($gpbType) {
            GPBType::UINT32 => $dec->readUint32(),
            GPBType::UINT64 => $dec->readUint64(),
            GPBType::INT32, GPBType::SINT32, GPBType::ENUM => $dec->readSint32(),
            GPBType::INT64, GPBType::SINT64 => $dec->readSint64(),
            GPBType::BOOL => $dec->readBoolByte(),
            GPBType::FIXED32 => $dec->readFixed32(),
            GPBType::FIXED64 => $dec->readFixed64(),
            GPBType::SFIXED32 => $dec->readSfixed32(),
            GPBType::SFIXED64 => $dec->readSfixed64(),
            GPBType::FLOAT => $dec->readFloat32(),
            GPBType::DOUBLE => $dec->readFloat64(),
            GPBType::STRING => $dec->readString(),
            GPBType::BYTES => $dec->readBytes(),
            default => throw new DecodeException("omnibuf: unsupported GPBType {$gpbType}"),
        };
    }

    private static function readCollectionItem(Decoder $dec, FieldDescriptor $fd): mixed
    {
        $gpbType = (int) $fd->getType();
        if ($gpbType === GPBType::MESSAGE) {
            $subDec = $dec->readSubMessageDecoder();
            $subDesc = self::resolveMessageDescriptor($fd);
            $subClass = (string) $subDesc->getClass();
            /** @var Message $child */
            $child = new $subClass();
            self::decodeInto($subDec, $child, $subDesc, false);
            return $child;
        }
        return self::readScalar($dec, $gpbType);
    }

    /**
     * @param array<int|string, mixed> $entries
     */
    private static function sortMapKeys(array &$entries, int $keyWireType): void
    {
        switch ($keyWireType) {
            case WireType::STRING:
                uksort($entries, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
                return;
            case WireType::UINT:
            case WireType::FIXED32:
            case WireType::FIXED64:
                uksort($entries, static fn(int|string $a, int|string $b): int => UInt64::compare(
                    \is_int($a) ? $a : UInt64::fromDecimalString($a),
                    \is_int($b) ? $b : UInt64::fromDecimalString($b),
                ));
                return;
            case WireType::SINT:
            case WireType::SFIXED32:
            case WireType::SFIXED64:
            case WireType::BOOL_FALSE:
                uksort($entries, static fn(int|string $a, int|string $b): int => ((int) $a) <=> ((int) $b));
                return;
        }
    }
}
