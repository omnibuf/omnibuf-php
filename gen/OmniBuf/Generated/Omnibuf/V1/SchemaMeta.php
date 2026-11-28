<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\V1;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\GeneratedMessage;
use OmniBuf\SchemaMeta;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class SchemaMeta implements GeneratedMessage
{
    private static int $nativeSchemaId = -2;

    public string $typeName = '';

    /** @var array<int, string> */
    public array $fields = [];

    /** @var array<int, \OmniBuf\Generated\Omnibuf\V1\SchemaMeta> */
    public array $children = [];

    public function __construct(
        ?string $typeName = null,
        ?array $fields = null,
        ?array $children = null,
    ) {
        if ($typeName !== null) { $this->typeName = $typeName; }
        if ($fields !== null) { $this->fields = $fields; }
        if ($children !== null) { $this->children = $children; }
    }

    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string
    {
        if (!$selfDescribing) {
            $schemaId = self::$nativeSchemaId;
            if ($schemaId >= 0) {
                return \omnibuf_native_encode($schemaId, $this, $deterministic);
            }
            if ($schemaId === -2) {
                $resolved = self::nativeSchemaId();
                if ($resolved !== null) {
                    return \omnibuf_native_encode($resolved, $this, $deterministic);
                }
            }
        }
        $enc = new Encoder();
        if ($selfDescribing) {
            self::schemaMeta()->writeTo($enc, true);
        }
        $this->marshalTo($enc, $deterministic);
        return $enc->finish();
    }

    public function marshalTo(Encoder $enc, bool $deterministic = false): void
    {
        if ($this->typeName !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($this->typeName));
            $enc->buf .= $this->typeName;
        }
        if ($this->fields !== []) {
            $entries = $this->fields;
            if ($deterministic) {
                uksort($entries, static fn(int|string $a, int|string $b): int => UInt64::compare((int) $a, (int) $b));
            }
            $enc->buf .= "\x5d";
            $enc->beginContainer();
            $enc->buf .= "\x00\x0a";
            $enc->writeVarint(\count($entries));
            foreach ($entries as $k => $v) {
                $enc->writeVarint($k & 0xFFFFFFFF);
                $enc->writeVarint(\strlen($v)); $enc->buf .= $v;
            }
            $enc->endContainer();
        }
        if ($this->children !== []) {
            $entries = $this->children;
            if ($deterministic) {
                uksort($entries, static fn(int|string $a, int|string $b): int => UInt64::compare((int) $a, (int) $b));
            }
            $enc->buf .= "\x7d";
            $enc->beginContainer();
            $enc->buf .= "\x00\x0c";
            $enc->writeVarint(\count($entries));
            foreach ($entries as $k => $v) {
                $enc->writeVarint($k & 0xFFFFFFFF);
                $enc->beginContainer(); $v->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
            }
            $enc->endContainer();
        }
    }

    public static function unmarshalOmnibuf(
        string $data,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): static {
        $schemaId = self::$nativeSchemaId;
        if ($schemaId >= 0) {
            /** @var static $msg */
            $msg = \omnibuf_native_decode($schemaId, $data, $maxDepth, $maxElements);
            return $msg;
        }
        if ($schemaId === -2) {
            $resolved = self::nativeSchemaId();
            if ($resolved !== null) {
                /** @var static $msg */
                $msg = \omnibuf_native_decode($resolved, $data, $maxDepth, $maxElements);
                return $msg;
            }
        }
        $msg = new self();
        $msg->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), false);
        return $msg;
    }

    /** @internal */
    public static function nativeSchemaId(): ?int
    {
        self::$nativeSchemaId = -1;
        return null;
    }

    public function unmarshalInto(
        string $data,
        bool $merge = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): void {
        $this->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), $merge);
    }

    public function decodeFrom(Decoder $dec, bool $merge = false): void
    {
        if (!$merge) {
            $this->typeName = '';
            $this->fields = [];
            $this->children = [];
        }
        $buf = $dec->buf;
        $end = $dec->end;
        while ($dec->pos < $end) {
            $b0 = \ord($buf[$dec->pos]);
            if (($b0 & 1) !== 0) {
                $tag = $b0 >> 1;
                $dec->pos++;
                $fieldNum = $tag >> 4;
                $wireType = $tag & 0x0F;
                if ($fieldNum === 0 && $wireType !== WireType::SUB_MSG) {
                    throw new \OmniBuf\Exception\DecodeException("omnibuf: field #0 must use WireSubMsg");
                }
            } else {
                [$fieldNum, $wireType] = $dec->readTag();
            }
            if ($fieldNum === 0) {
                $dec->readField0();
                continue;
            }
            switch ($fieldNum) {
                case 1:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->typeName = $dec->readString();
                    break;
                case 2:
                    if ($wireType !== WireType::MAP) { $dec->skipUnknown($wireType); break; }
                    [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(0, 10);
                    for ($i = 0; $i < $count; $i++) {
                        $k = $mapDec->readUint32();
                        $this->fields[$k] = $mapDec->readString();
                    }
                    $mapDec->assertAtEnd('WireMap');
                    break;
                case 3:
                    if ($wireType !== WireType::MAP) { $dec->skipUnknown($wireType); break; }
                    [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(0, 12);
                    for ($i = 0; $i < $count; $i++) {
                        $k = $mapDec->readUint32();
                        $this->children[$k] = (static function () use ($mapDec): \OmniBuf\Generated\Omnibuf\V1\SchemaMeta { $c = new \OmniBuf\Generated\Omnibuf\V1\SchemaMeta(); $c->decodeFrom($mapDec->readSubMessageDecoder(), false); return $c; })();
                    }
                    $mapDec->assertAtEnd('WireMap');
                    break;
                default:
                    $dec->skipUnknown($wireType);
                    break;
            }
        }
    }

    /** @param array<class-string, true> $visited */
    public static function schemaMeta(array $visited = []): SchemaMeta
    {
        $visited[self::class] = true;
        $children = [];
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\V1\SchemaMeta::class])) {
            $children[3] = \OmniBuf\Generated\Omnibuf\V1\SchemaMeta::schemaMeta($visited);
        }
        return new SchemaMeta(
            'omnibuf.v1.SchemaMeta',
            [
                1 => 'type_name',
                2 => 'fields',
                3 => 'children',
            ],
            $children,
        );
    }
}
