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

final class PeerUpdate implements GeneratedMessage
{
    private static int $nativeSchemaId = -2;

    public int $asn = 0;

    public string $routerId = '';

    public bool $active = false;

    public int $uptimeSec = 0;

    public int $rssiDbm = 0;

    public float $cpuLoad = 0.0;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<int> */
    public array $prefixes = [];

    public ?\OmniBuf\Generated\Omnibuf\Bench\Location $location = null;

    /** @var list<\OmniBuf\Generated\Omnibuf\Bench\Neighbor> */
    public array $neighbors = [];

    public int $sequence = 0;

    public function __construct(
        ?int $asn = null,
        ?string $routerId = null,
        ?bool $active = null,
        ?int $uptimeSec = null,
        ?int $rssiDbm = null,
        ?float $cpuLoad = null,
        ?array $tags = null,
        ?array $prefixes = null,
        ?\OmniBuf\Generated\Omnibuf\Bench\Location $location = null,
        ?array $neighbors = null,
        ?int $sequence = null,
    ) {
        if ($asn !== null) { $this->asn = $asn; }
        if ($routerId !== null) { $this->routerId = $routerId; }
        if ($active !== null) { $this->active = $active; }
        if ($uptimeSec !== null) { $this->uptimeSec = $uptimeSec; }
        if ($rssiDbm !== null) { $this->rssiDbm = $rssiDbm; }
        if ($cpuLoad !== null) { $this->cpuLoad = $cpuLoad; }
        if ($tags !== null) { $this->tags = $tags; }
        if ($prefixes !== null) { $this->prefixes = $prefixes; }
        if ($location !== null) { $this->location = $location; }
        if ($neighbors !== null) { $this->neighbors = $neighbors; }
        if ($sequence !== null) { $this->sequence = $sequence; }
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
        if ($this->asn !== 0) {
            $enc->buf .= "\x21";
            $enc->writeVarint($this->asn & 0xFFFFFFFF);
        }
        if ($this->routerId !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->routerId));
            $enc->buf .= $this->routerId;
        }
        if ($this->active) {
            $enc->buf .= "\x67";
        }
        if ($this->uptimeSec !== 0) {
            $enc->buf .= "\x81";
            $enc->writeVarint($this->uptimeSec);
        }
        if ($this->rssiDbm !== 0) {
            $enc->buf .= "\xa3";
            $n = ($this->rssiDbm << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->cpuLoad !== 0.0 || pack('e', $this->cpuLoad) !== "\x00\x00\x00\x00\x00\x00\x00\x00") {
            $enc->buf .= "\xd3" . pack('e', $this->cpuLoad);
        }
        if ($this->tags !== []) {
            $enc->buf .= "\xfb";
            $enc->beginContainer();
            $enc->buf .= "\x0a";
            $enc->writeVarint(\count($this->tags));
            foreach ($this->tags as $item) {
                $enc->writeVarint(\strlen($item)); $enc->buf .= $item;
            }
            $enc->endContainer();
        }
        if ($this->prefixes !== []) {
            $enc->buf .= "\x36\x02";
            $enc->beginContainer();
            $enc->buf .= "\x00";
            $enc->writeVarint(\count($this->prefixes));
            foreach ($this->prefixes as $item) {
                $enc->writeVarint($item & 0xFFFFFFFF);
            }
            $enc->endContainer();
        }
        if ($this->location !== null) {
            $enc->buf .= "\x72\x02";
            $enc->beginContainer();
            $this->location->marshalTo($enc, $deterministic);
            $enc->endContainer();
        }
        if ($this->neighbors !== []) {
            $enc->buf .= "\xb6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x0c";
            $enc->writeVarint(\count($this->neighbors));
            foreach ($this->neighbors as $item) {
                $enc->beginContainer(); $item->marshalTo($enc, $deterministic ?? true); $enc->endContainer();
            }
            $enc->endContainer();
        }
        if ($this->sequence !== 0) {
            $enc->buf .= "\xc2\x02";
            $enc->writeVarint($this->sequence);
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
        $specs[] = ['number' => 1, 'prop' => 'asn', 'kind' => 'uint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 2, 'prop' => 'routerId', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 3, 'prop' => 'active', 'kind' => 'bool', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 4, 'prop' => 'uptimeSec', 'kind' => 'uint64', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 5, 'prop' => 'rssiDbm', 'kind' => 'sint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 6, 'prop' => 'cpuLoad', 'kind' => 'float64', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 7, 'prop' => 'tags', 'kind' => 'array', 'elemKind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 8, 'prop' => 'prefixes', 'kind' => 'array', 'elemKind' => 'uint32', 'omitEmpty' => true, 'nullable' => false];
        $sub_9 = \OmniBuf\Generated\Omnibuf\Bench\Location::nativeSchemaId();
        if ($sub_9 === null) { return null; }
        $specs[] = ['number' => 9, 'prop' => 'location', 'kind' => 'message', 'subSchemaId' => $sub_9, 'omitEmpty' => true, 'nullable' => true];
        $sub_10 = \OmniBuf\Generated\Omnibuf\Bench\Neighbor::nativeSchemaId();
        if ($sub_10 === null) { return null; }
        $specs[] = ['number' => 10, 'prop' => 'neighbors', 'kind' => 'array', 'elemKind' => 'message', 'subSchemaId' => $sub_10, 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 11, 'prop' => 'sequence', 'kind' => 'uint64', 'omitEmpty' => true, 'nullable' => false];
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
            $this->asn = 0;
            $this->routerId = '';
            $this->active = false;
            $this->uptimeSec = 0;
            $this->rssiDbm = 0;
            $this->cpuLoad = 0.0;
            $this->tags = [];
            $this->prefixes = [];
            $this->location = null;
            $this->neighbors = [];
            $this->sequence = 0;
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
                    $this->asn = $dec->readUint32();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->routerId = $dec->readString();
                    break;
                case 3:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->active = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->active = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 4:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->uptimeSec = $dec->readUint64();
                    break;
                case 5:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->rssiDbm = $dec->readSint32();
                    break;
                case 6:
                    if ($wireType !== 9) { $dec->skipUnknown($wireType); break; }
                    $this->cpuLoad = $dec->readFloat64();
                    break;
                case 7:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(10);
                    for ($i = 0; $i < $count; $i++) {
                        $this->tags[] = $arrDec->readString();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 8:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(0);
                    for ($i = 0; $i < $count; $i++) {
                        $this->prefixes[] = $arrDec->readUint32();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 9:
                    if ($wireType !== 12) { $dec->skipUnknown($wireType); break; }
                    $subDec = $dec->readSubMessageDecoder();
                    if ($this->location === null) {
                        $this->location = new \OmniBuf\Generated\Omnibuf\Bench\Location();
                        $this->location->decodeFrom($subDec, false);
                    } else {
                        $this->location->decodeFrom($subDec, true);
                    }
                    break;
                case 10:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(12);
                    for ($i = 0; $i < $count; $i++) {
                        $this->neighbors[] = (static function () use ($arrDec): \OmniBuf\Generated\Omnibuf\Bench\Neighbor { $c = new \OmniBuf\Generated\Omnibuf\Bench\Neighbor(); $c->decodeFrom($arrDec->readSubMessageDecoder(), false); return $c; })();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 11:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->sequence = $dec->readUint64();
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
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\Bench\Location::class])) {
            $children[9] = \OmniBuf\Generated\Omnibuf\Bench\Location::schemaMeta($visited);
        }
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\Bench\Neighbor::class])) {
            $children[10] = \OmniBuf\Generated\Omnibuf\Bench\Neighbor::schemaMeta($visited);
        }
        return new SchemaMeta(
            'omnibuf.bench.PeerUpdate',
            [
                1 => 'asn',
                2 => 'router_id',
                3 => 'active',
                4 => 'uptime_sec',
                5 => 'rssi_dbm',
                6 => 'cpu_load',
                7 => 'tags',
                8 => 'prefixes',
                9 => 'location',
                10 => 'neighbors',
                11 => 'sequence',
            ],
            $children,
        );
    }
}
