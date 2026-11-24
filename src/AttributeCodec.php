<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
use OmniBuf\Internal\FieldMeta;
use OmniBuf\Internal\MessageMeta;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Tier 3 reflection-cached codec for native PHP classes annotated with
 * `#[OmniBufMessage]` and `#[OmniBufField]`.
 */
final class AttributeCodec
{
    /**
     * @var array<class-string, MessageMeta>
     */
    private static array $cache = [];

    /**
     * @var array<class-string, int|false>
     */
    private static array $nativeSchemas = [];

    public static function marshal(object $msg, bool $deterministic = false, bool $selfDescribing = false): string
    {
        if (!$selfDescribing && \function_exists('omnibuf_native_encode')) {
            $schemaId = self::nativeSchemaId($msg::class);
            if ($schemaId !== null) {
                return \omnibuf_native_encode($schemaId, $msg, $deterministic);
            }
        }
        $enc = new Encoder();
        if ($selfDescribing) {
            self::schemaMetaForValue($msg, 1)->writeTo($enc, true);
        }
        self::marshalTo($msg, $enc, $deterministic);
        return $enc->finish();
    }

    public static function marshalTo(object $msg, Encoder $enc, bool $deterministic = false, int $depth = 0): void
    {
        if ($depth > Wire::MAX_NESTING_DEPTH) {
            throw new EncodeException("omnibuf: encode nesting depth {$depth} exceeds maximum " . Wire::MAX_NESTING_DEPTH);
        }
        $meta = self::describe($msg::class);
        foreach ($meta->fields as $f) {
            $prop = $f->prop;
            $val = $msg->{$prop};
            $num = $f->number;
            $kind = $f->kind;

            if ($kind === 'message') {
                if ($val === null) {
                    continue;
                }
                if (!\is_object($val)) {
                    throw new EncodeException("omnibuf: field #{$num} ({$prop}) expected object");
                }
                $enc->writeTag($num, WireType::SUB_MSG);
                $enc->beginContainer();
                self::marshalTo($val, $enc, $deterministic, $depth + 1);
                $enc->endContainer();
                continue;
            }

            if ($kind === 'array') {
                if (!\is_array($val) || ($val === [] && $f->omitEmpty)) {
                    continue;
                }
                $enc->writeTag($num, WireType::ARRAY);
                $enc->beginContainer();
                $enc->writeByte($f->elemWireType);
                $enc->writeVarint(\count($val));
                foreach ($val as $item) {
                    self::writeCollectionItem($enc, $f->elemKind, $item, $deterministic, $depth + 1);
                }
                $enc->endContainer();
                continue;
            }

            if ($kind === 'map') {
                if (!\is_array($val) || ($val === [] && $f->omitEmpty)) {
                    continue;
                }
                $entries = $val;
                if ($deterministic) {
                    self::sortMapKeys($entries, $f->keyWireType);
                }
                $enc->writeTag($num, WireType::MAP);
                $enc->beginContainer();
                $enc->writeByte($f->keyWireType);
                $enc->writeByte($f->valWireType);
                $enc->writeVarint(\count($entries));
                foreach ($entries as $k => $v) {
                    self::writeCollectionItem($enc, $f->keyKind, $k, $deterministic, $depth + 1);
                    self::writeCollectionItem($enc, $f->valKind, $v, $deterministic, $depth + 1);
                }
                $enc->endContainer();
                continue;
            }

            if ($val === null) {
                continue;
            }

            if ($f->omitEmpty && !$f->nullable && self::isZeroScalar($kind, $val)) {
                continue;
            }

            self::writeScalarField($enc, $num, $kind, $val);
        }
    }

    /**
     * @template T of object
     * @param class-string<T>|T $target
     * @return T
     */
    public static function unmarshal(
        string $data,
        string|object $target,
        bool $merge = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): object {
        if (!$merge && \is_string($target) && \function_exists('omnibuf_native_decode')) {
            $schemaId = self::nativeSchemaId($target);
            if ($schemaId !== null) {
                /** @var T $decoded */
                $decoded = \omnibuf_native_decode($schemaId, $data, $maxDepth, $maxElements);
                return $decoded;
            }
        }
        $obj = \is_string($target) ? new $target() : $target;
        $dec = new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements);
        self::decodeInto($dec, $obj, $merge);
        /** @var T $obj */
        return $obj;
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $visiting
     */
    private static function nativeSchemaId(string $class, array $visiting = []): ?int
    {
        if (isset(self::$nativeSchemas[$class])) {
            $cached = self::$nativeSchemas[$class];
            return $cached === false ? null : $cached;
        }
        if (isset($visiting[$class]) || !\function_exists('omnibuf_native_register_schema')) {
            return null;
        }
        $visiting[$class] = true;
        $meta = self::describe($class);
        $specs = [];
        foreach ($meta->fields as $f) {
            $spec = [
                'number' => $f->number,
                'prop' => $f->prop,
                'kind' => $f->kind,
                'omitEmpty' => $f->omitEmpty,
                'nullable' => $f->nullable,
            ];
            if ($f->kind === 'message') {
                if ($f->messageClass === null) {
                    self::$nativeSchemas[$class] = false;
                    return null;
                }
                $subId = self::nativeSchemaId($f->messageClass, $visiting);
                if ($subId === null) {
                    self::$nativeSchemas[$class] = false;
                    return null;
                }
                $spec['subSchemaId'] = $subId;
            } elseif ($f->kind === 'array') {
                $spec['elemKind'] = $f->elemKind;
                if ($f->elemKind === 'message') {
                    if ($f->messageClass === null) {
                        self::$nativeSchemas[$class] = false;
                        return null;
                    }
                    $subId = self::nativeSchemaId($f->messageClass, $visiting);
                    if ($subId === null) {
                        self::$nativeSchemas[$class] = false;
                        return null;
                    }
                    $spec['subSchemaId'] = $subId;
                }
            } elseif ($f->kind === 'map') {
                $spec['keyKind'] = $f->keyKind;
                $spec['valKind'] = $f->valKind;
                if ($f->valKind === 'message') {
                    if ($f->messageClass === null) {
                        self::$nativeSchemas[$class] = false;
                        return null;
                    }
                    $subId = self::nativeSchemaId($f->messageClass, $visiting);
                    if ($subId === null) {
                        self::$nativeSchemas[$class] = false;
                        return null;
                    }
                    $spec['subSchemaId'] = $subId;
                }
            }
            $specs[] = $spec;
        }
        $id = \omnibuf_native_register_schema($class, $specs);
        self::$nativeSchemas[$class] = $id;
        return $id;
    }

    public static function decodeInto(Decoder $dec, object $obj, bool $merge = false): void
    {
        $meta = self::describe($obj::class);
        if (!$merge) {
            self::resetObject($obj, $meta);
        }
        $byNumber = $meta->byNumber;

        while ($dec->hasMore()) {
            [$fieldNum, $wireType] = $dec->readTag();
            if ($fieldNum === 0) {
                $dec->readField0();
                continue;
            }
            if (!isset($byNumber[$fieldNum])) {
                $dec->skipUnknown($wireType);
                continue;
            }
            $f = $byNumber[$fieldNum];
            $prop = $f->prop;
            $kind = $f->kind;

            if ($kind === 'bool') {
                if ($wireType === WireType::BOOL_FALSE) {
                    $obj->{$prop} = false;
                    continue;
                }
                if ($wireType === WireType::BOOL_TRUE) {
                    $obj->{$prop} = true;
                    continue;
                }
                $dec->skipUnknown($wireType);
                continue;
            }

            if ($wireType !== $f->wireType) {
                $dec->skipUnknown($wireType);
                continue;
            }

            if ($kind === 'message') {
                $subDec = $dec->readSubMessageDecoder();
                $cls = $f->messageClass;
                if ($cls === null) {
                    throw new DecodeException("omnibuf: missing messageClass on field #{$fieldNum} ({$prop})");
                }
                $existing = $obj->{$prop};
                if ($existing instanceof $cls) {
                    self::decodeInto($subDec, $existing, true);
                } else {
                    $child = new $cls();
                    self::decodeInto($subDec, $child, false);
                    $obj->{$prop} = $child;
                }
                continue;
            }

            if ($kind === 'array') {
                [$arrDec, $_et, $count] = $dec->readArrayHeader($f->elemWireType);
                /** @var list<mixed> $arr */
                $arr = \is_array($obj->{$prop}) ? $obj->{$prop} : [];
                for ($i = 0; $i < $count; $i++) {
                    $arr[] = self::readCollectionItem($arrDec, $f->elemKind, $f->messageClass);
                }
                $arrDec->assertAtEnd('WireArray');
                $obj->{$prop} = $arr;
                continue;
            }

            if ($kind === 'map') {
                [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader($f->keyWireType, $f->valWireType);
                /** @var array<int|string, mixed> $map */
                $map = \is_array($obj->{$prop}) ? $obj->{$prop} : [];
                for ($i = 0; $i < $count; $i++) {
                    $k = self::readMapKey($mapDec, $f->keyKind);
                    $map[$k] = self::readCollectionItem($mapDec, $f->valKind, $f->messageClass);
                }
                $mapDec->assertAtEnd('WireMap');
                $obj->{$prop} = $map;
                continue;
            }

            $obj->{$prop} = self::readScalar($dec, $kind);
        }
    }

    /**
     * Builds a depth-bounded SchemaMeta for a concrete object instance (safe for recursive types).
     */
    public static function schemaMetaForValue(object $msg, int $schemaDepth = 1): SchemaMeta
    {
        $meta = self::describe($msg::class);
        $fields = [];
        $children = [];
        // Each SchemaMeta.children hop adds +2 depth (children map + child SchemaMeta submsg);
        // leaf SchemaMeta.fields map adds +1 depth, so only expand children when childDepth + 1 <= MAX_NESTING_DEPTH.
        $canExpandChildren = ($schemaDepth + 3) <= Wire::MAX_NESTING_DEPTH;

        foreach ($meta->fields as $f) {
            $fields[$f->number] = $f->wireName;
            if (!$canExpandChildren || $f->messageClass === null) {
                continue;
            }
            $val = $msg->{$f->prop};
            if ($f->kind === 'message' && \is_object($val)) {
                $children[$f->number] = self::schemaMetaForValue($val, $schemaDepth + 2);
            } elseif ($f->kind === 'array' && \is_array($val)) {
                $merged = null;
                foreach ($val as $item) {
                    if (\is_object($item)) {
                        $merged = SchemaMeta::merge($merged, self::schemaMetaForValue($item, $schemaDepth + 2));
                    }
                }
                if ($merged !== null) {
                    $children[$f->number] = $merged;
                }
            } elseif ($f->kind === 'map' && \is_array($val)) {
                $merged = null;
                foreach ($val as $item) {
                    if (\is_object($item)) {
                        $merged = SchemaMeta::merge($merged, self::schemaMetaForValue($item, $schemaDepth + 2));
                    }
                }
                if ($merged !== null) {
                    $children[$f->number] = $merged;
                }
            }
        }
        return new SchemaMeta($meta->name, $fields, $children);
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $visited
     */
    public static function schemaMetaFor(string $class, array $visited = []): SchemaMeta
    {
        $meta = self::describe($class);
        $fields = [];
        $children = [];
        $visited[$class] = true;

        foreach ($meta->fields as $f) {
            $fields[$f->number] = $f->wireName;
            if ($f->messageClass !== null && !isset($visited[$f->messageClass])) {
                $children[$f->number] = self::schemaMetaFor($f->messageClass, $visited);
            }
        }
        return new SchemaMeta($meta->name, $fields, $children);
    }

    private static function resetObject(object $obj, MessageMeta $meta): void
    {
        foreach ($meta->fields as $f) {
            $prop = $f->prop;
            if ($f->kind === 'message') {
                if ($f->nullable) {
                    $obj->{$prop} = null;
                } elseif ($f->messageClass !== null) {
                    $cls = $f->messageClass;
                    $obj->{$prop} = new $cls();
                }
            } elseif ($f->kind === 'array' || $f->kind === 'map') {
                $obj->{$prop} = [];
            } else {
                $obj->{$prop} = $f->default;
            }
        }
    }

    private static function isZeroScalar(string $kind, mixed $val): bool
    {
        return match ($kind) {
            'uint32', 'sint32', 'sint64', 'fixed32', 'sfixed32', 'sfixed64' => (int) $val === 0,
            'uint64', 'fixed64' => (\is_int($val) ? $val : UInt64::fromDecimalString((string) $val)) === 0,
            'bool' => $val === false,
            'float32' => pack('g', (float) $val) === "\x00\x00\x00\x00",
            'float64' => (float) $val === 0.0 && pack('e', (float) $val) === "\x00\x00\x00\x00\x00\x00\x00\x00",
            'string', 'bytes' => $val === '',
            default => false,
        };
    }

    private static function writeScalarField(Encoder $enc, int $num, string $kind, mixed $val): void
    {
        switch ($kind) {
            case 'uint32':
                $enc->writeUint32Field($num, (int) $val);
                return;
            case 'uint64':
                $enc->writeUintField($num, \is_string($val) ? $val : (int) $val);
                return;
            case 'sint32':
                $enc->writeSint32Field($num, (int) $val);
                return;
            case 'sint64':
                $enc->writeSintField($num, (int) $val);
                return;
            case 'bool':
                $enc->writeBoolField($num, (bool) $val);
                return;
            case 'fixed32':
                $enc->writeFixed32Field($num, (int) $val);
                return;
            case 'fixed64':
                $enc->writeFixed64Field($num, \is_string($val) ? $val : (int) $val);
                return;
            case 'sfixed32':
                $enc->writeSfixed32Field($num, (int) $val);
                return;
            case 'sfixed64':
                $enc->writeSfixed64Field($num, (int) $val);
                return;
            case 'float32':
                $enc->writeFloat32Field($num, (float) $val);
                return;
            case 'float64':
                $enc->writeFloat64Field($num, (float) $val);
                return;
            case 'string':
                $enc->writeStringField($num, (string) $val);
                return;
            case 'bytes':
                $enc->writeBytesField($num, (string) $val);
                return;
            default:
                throw new EncodeException("omnibuf: unsupported scalar kind '{$kind}'");
        }
    }

    private static function writeCollectionItem(Encoder $enc, string $kind, mixed $val, bool $deterministic, int $depth): void
    {
        switch ($kind) {
            case 'uint32':
                $enc->writeVarint(((int) $val) & 0xFFFFFFFF);
                return;
            case 'uint64':
                $enc->writeVarint(\is_int($val) ? $val : UInt64::fromDecimalString((string) $val));
                return;
            case 'sint32':
                $enc->writeSintRaw((((int) $val) << 32) >> 32);
                return;
            case 'sint64':
                $enc->writeSintRaw((int) $val);
                return;
            case 'bool':
                $enc->writeByte(((bool) $val) ? 1 : 0);
                return;
            case 'fixed32':
            case 'sfixed32':
                $enc->writeFixed32Raw((int) $val);
                return;
            case 'fixed64':
                $enc->writeFixed64Raw(\is_string($val) ? $val : (int) $val);
                return;
            case 'sfixed64':
                $enc->writeFixed64Raw((int) $val);
                return;
            case 'float32':
                $enc->writeFloat32Raw((float) $val);
                return;
            case 'float64':
                $enc->writeFloat64Raw((float) $val);
                return;
            case 'string':
                $enc->writeRawStringPayload((string) $val);
                return;
            case 'bytes':
                $enc->writeRawBytesPayload((string) $val);
                return;
            case 'message':
                if (!\is_object($val)) {
                    throw new EncodeException('omnibuf: expected object for message collection item');
                }
                $enc->beginContainer();
                self::marshalTo($val, $enc, $deterministic, $depth + 1);
                $enc->endContainer();
                return;
            default:
                throw new EncodeException("omnibuf: unsupported collection item kind '{$kind}'");
        }
    }

    private static function readScalar(Decoder $dec, string $kind): mixed
    {
        return match ($kind) {
            'uint32' => $dec->readUint32(),
            'uint64' => $dec->readUint64(),
            'sint32' => $dec->readSint32(),
            'sint64' => $dec->readSint64(),
            'fixed32' => $dec->readFixed32(),
            'fixed64' => $dec->readFixed64(),
            'sfixed32' => $dec->readSfixed32(),
            'sfixed64' => $dec->readSfixed64(),
            'float32' => $dec->readFloat32(),
            'float64' => $dec->readFloat64(),
            'string' => $dec->readString(),
            'bytes' => $dec->readBytes(),
            default => throw new DecodeException("omnibuf: unsupported scalar kind '{$kind}'"),
        };
    }

    private static function readMapKey(Decoder $dec, string $kind): int|string
    {
        return match ($kind) {
            'string' => $dec->readString(),
            'uint32' => $dec->readUint32(),
            'uint64' => $dec->readUint64(),
            'sint32' => $dec->readSint32(),
            'sint64' => $dec->readSint64(),
            'fixed32' => $dec->readFixed32(),
            'fixed64' => $dec->readFixed64(),
            'sfixed32' => $dec->readSfixed32(),
            'sfixed64' => $dec->readSfixed64(),
            'bool' => $dec->readBoolByte() ? 1 : 0,
            default => throw new DecodeException("omnibuf: unsupported map key kind '{$kind}'"),
        };
    }

    /**
     * @param ?class-string $messageClass
     */
    private static function readCollectionItem(Decoder $dec, string $kind, ?string $messageClass): mixed
    {
        if ($kind === 'bool') {
            return $dec->readBoolByte();
        }
        if ($kind === 'message') {
            if ($messageClass === null) {
                throw new DecodeException('omnibuf: missing messageClass for message collection item');
            }
            $subDec = $dec->readSubMessageDecoder();
            $child = new $messageClass();
            self::decodeInto($subDec, $child, false);
            return $child;
        }
        return self::readScalar($dec, $kind);
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

    /**
     * @param class-string $class
     */
    private static function describe(string $class): MessageMeta
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $ref = new ReflectionClass($class);
        $msgAttrs = $ref->getAttributes(OmniBufMessage::class);
        $msgName = $ref->getShortName();
        if ($msgAttrs !== []) {
            /** @var OmniBufMessage $attr */
            $attr = $msgAttrs[0]->newInstance();
            if ($attr->name !== '') {
                $msgName = $attr->name;
            }
        }

        /** @var list<FieldMeta> $fields */
        $fields = [];
        /** @var array<int, FieldMeta> $byNumber */
        $byNumber = [];
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(OmniBufField::class);
            if ($attrs === []) {
                continue;
            }
            /** @var OmniBufField $fa */
            $fa = $attrs[0]->newInstance();
            if ($fa->number < 1 || $fa->number > Wire::MAX_FIELD_NUMBER) {
                throw new EncodeException("omnibuf: field number {$fa->number} on {$class}::\${$prop->getName()} out of range [1.." . Wire::MAX_FIELD_NUMBER . ']');
            }
            if (isset($byNumber[$fa->number])) {
                throw new EncodeException("omnibuf: duplicate field number #{$fa->number} on {$class}");
            }
            $type = $prop->getType();
            $nullable = $type === null || $type->allowsNull();
            $phpTypeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            $kind = self::normalizeKind($fa->wire, $phpTypeName);
            $wireType = self::kindToWireType($kind);
            $messageClass = $fa->messageClass;
            if ($kind === 'message' && $messageClass === null && $phpTypeName !== null && class_exists($phpTypeName)) {
                $messageClass = $phpTypeName;
            }

            $elemKind = $fa->elementWire !== null ? self::normalizeKind($fa->elementWire, null) : '';
            $elemWireType = $elemKind !== '' ? self::kindToWireType($elemKind) : 0;
            $keyKind = $fa->keyWire !== null ? self::normalizeKind($fa->keyWire, null) : '';
            $keyWireType = $keyKind !== '' ? self::kindToWireType($keyKind) : 0;
            $valKind = $fa->valueWire !== null ? self::normalizeKind($fa->valueWire, null) : '';
            $valWireType = $valKind !== '' ? self::kindToWireType($valKind) : 0;

            $default = $prop->hasDefaultValue() ? $prop->getDefaultValue() : self::defaultForKind($kind, $nullable);

            /** @var ?class-string $mc */
            $mc = $messageClass;
            $entry = new FieldMeta(
                number: $fa->number,
                prop: $prop->getName(),
                wireName: $fa->name !== '' ? $fa->name : $prop->getName(),
                kind: $kind,
                wireType: $wireType,
                elemKind: $elemKind,
                elemWireType: $elemWireType,
                keyKind: $keyKind,
                keyWireType: $keyWireType,
                valKind: $valKind,
                valWireType: $valWireType,
                messageClass: $mc,
                omitEmpty: $fa->omitEmpty,
                nullable: $nullable,
                default: $default,
            );
            $fields[] = $entry;
            $byNumber[$fa->number] = $entry;
        }

        usort($fields, static fn(FieldMeta $a, FieldMeta $b): int => $a->number <=> $b->number);
        self::$cache[$class] = new MessageMeta($msgName, $fields, $byNumber);
        return self::$cache[$class];
    }

    private static function normalizeKind(string $wire, ?string $phpType): string
    {
        if ($wire !== '') {
            return match (strtolower($wire)) {
                'uint32' => 'uint32',
                'uint', 'uint64' => 'uint64',
                'sint32', 'int32', 'enum' => 'sint32',
                'sint', 'sint64', 'int64' => 'sint64',
                'bool' => 'bool',
                'fixed32' => 'fixed32',
                'fixed64' => 'fixed64',
                'sfixed32' => 'sfixed32',
                'sfixed64' => 'sfixed64',
                'float', 'float32' => 'float32',
                'double', 'float64' => 'float64',
                'string' => 'string',
                'bytes' => 'bytes',
                'submsg', 'message' => 'message',
                'array' => 'array',
                'map' => 'map',
                default => throw new EncodeException("omnibuf: unknown wire kind '{$wire}'"),
            };
        }
        return match ($phpType) {
            'int' => 'sint64',
            'bool' => 'bool',
            'float' => 'float64',
            'string' => 'string',
            'array' => 'array',
            default => ($phpType !== null && class_exists($phpType)) ? 'message' : 'string',
        };
    }

    private static function kindToWireType(string $kind): int
    {
        return match ($kind) {
            'uint32', 'uint64' => WireType::UINT,
            'sint32', 'sint64' => WireType::SINT,
            'bool' => WireType::BOOL_FALSE,
            'fixed32' => WireType::FIXED32,
            'fixed64' => WireType::FIXED64,
            'sfixed32' => WireType::SFIXED32,
            'sfixed64' => WireType::SFIXED64,
            'float32' => WireType::FLOAT32,
            'float64' => WireType::FLOAT64,
            'string' => WireType::STRING,
            'bytes' => WireType::BYTES,
            'message' => WireType::SUB_MSG,
            'array' => WireType::ARRAY,
            'map' => WireType::MAP,
            default => throw new EncodeException("omnibuf: cannot map kind '{$kind}' to wire type"),
        };
    }

    private static function defaultForKind(string $kind, bool $nullable): mixed
    {
        if ($kind === 'message') {
            return null;
        }
        if ($kind === 'array' || $kind === 'map') {
            return [];
        }
        if ($nullable) {
            return null;
        }
        return match ($kind) {
            'uint32', 'uint64', 'sint32', 'sint64', 'fixed32', 'fixed64', 'sfixed32', 'sfixed64' => 0,
            'bool' => false,
            'float32', 'float64' => 0.0,
            'string', 'bytes' => '',
            default => null,
        };
    }
}
