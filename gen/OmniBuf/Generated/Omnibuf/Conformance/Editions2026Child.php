<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Conformance;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\GeneratedMessage;
use OmniBuf\SchemaMeta;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class Editions2026Child implements GeneratedMessage
{
    private static int $nativeSchemaId = -2;

    public ?int $id = null;

    public ?string $note = null;

    public function __construct(
        ?int $id = null,
        ?string $note = null,
    ) {
        if ($id !== null) { $this->id = $id; }
        if ($note !== null) { $this->note = $note; }
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
        if ($this->id !== null) {
            $enc->buf .= "\x23";
            $n = ($this->id << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->note !== null) {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->note));
            $enc->buf .= $this->note;
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
        if (self::$nativeSchemaId !== -2) {
            return self::$nativeSchemaId >= 0 ? self::$nativeSchemaId : null;
        }
        self::$nativeSchemaId = -1;
        if (!\function_exists('omnibuf_native_register_schema')) {
            return null;
        }
        $specs = [];
        $specs[] = ['number' => 1, 'prop' => 'id', 'kind' => 'sint32', 'omitEmpty' => false, 'nullable' => true];
        $specs[] = ['number' => 2, 'prop' => 'note', 'kind' => 'string', 'omitEmpty' => false, 'nullable' => true];
        self::$nativeSchemaId = \omnibuf_native_register_schema(self::class, $specs);
        return self::$nativeSchemaId;
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
            $this->id = null;
            $this->note = null;
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
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->id = $dec->readSint32();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->note = $dec->readString();
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
        return new SchemaMeta(
            'omnibuf.conformance.Editions2026Child',
            [
                1 => 'id',
                2 => 'note',
            ],
            $children,
        );
    }
}
