<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\Bench;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\GeneratedMessage;
use OmniBuf\SchemaMeta;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class Neighbor implements GeneratedMessage
{
    private static ?int $nativeSchemaId = null;
    private static bool $nativeSchemaChecked = false;

    public int $peerAsn = 0;

    public string $router = '';

    public int $latencyUs = 0;

    public bool $established = false;

    public function __construct(
        ?int $peerAsn = null,
        ?string $router = null,
        ?int $latencyUs = null,
        ?bool $established = null,
    ) {
        if ($peerAsn !== null) { $this->peerAsn = $peerAsn; }
        if ($router !== null) { $this->router = $router; }
        if ($latencyUs !== null) { $this->latencyUs = $latencyUs; }
        if ($established !== null) { $this->established = $established; }
    }

    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string
    {
        if (!$selfDescribing && \function_exists('omnibuf_native_encode')) {
            $schemaId = self::nativeSchemaId();
            if ($schemaId !== null) {
                return \omnibuf_native_encode($schemaId, $this, $deterministic);
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
        if ($this->peerAsn !== 0) {
            $enc->buf .= "\x21";
            $enc->writeVarint($this->peerAsn & 0xFFFFFFFF);
        }
        if ($this->router !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->router));
            $enc->buf .= $this->router;
        }
        if ($this->latencyUs !== 0) {
            $enc->buf .= "\x61";
            $enc->writeVarint($this->latencyUs & 0xFFFFFFFF);
        }
        if ($this->established) {
            $enc->buf .= "\x87";
        }
    }

    public static function unmarshalOmnibuf(
        string $data,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): static {
        if (\function_exists('omnibuf_native_decode')) {
            $schemaId = self::nativeSchemaId();
            if ($schemaId !== null) {
                /** @var static $msg */
                $msg = \omnibuf_native_decode($schemaId, $data, $maxDepth, $maxElements);
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
        if (self::$nativeSchemaChecked) {
            return self::$nativeSchemaId;
        }
        self::$nativeSchemaChecked = true;
        if (!\function_exists('omnibuf_native_register_schema')) {
            return null;
        }
        $specs = [];
        $specs[] = ['number' => 1, 'prop' => 'peerAsn', 'kind' => 'uint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 2, 'prop' => 'router', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 3, 'prop' => 'latencyUs', 'kind' => 'uint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 4, 'prop' => 'established', 'kind' => 'bool', 'omitEmpty' => true, 'nullable' => false];
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
            $this->peerAsn = 0;
            $this->router = '';
            $this->latencyUs = 0;
            $this->established = false;
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
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->peerAsn = $dec->readUint32();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->router = $dec->readString();
                    break;
                case 3:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->latencyUs = $dec->readUint32();
                    break;
                case 4:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->established = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->established = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
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
            'omnibuf.bench.Neighbor',
            [
                1 => 'peer_asn',
                2 => 'router',
                3 => 'latency_us',
                4 => 'established',
            ],
            $children,
        );
    }
}
