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

final class Location implements GeneratedMessage
{
    private static ?int $nativeSchemaId = null;
    private static bool $nativeSchemaChecked = false;

    public string $region = '';

    public string $datacenter = '';

    public string $city = '';

    public int $rack = 0;

    public float $latitude = 0.0;

    public float $longitude = 0.0;

    public function __construct(
        ?string $region = null,
        ?string $datacenter = null,
        ?string $city = null,
        ?int $rack = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ) {
        if ($region !== null) { $this->region = $region; }
        if ($datacenter !== null) { $this->datacenter = $datacenter; }
        if ($city !== null) { $this->city = $city; }
        if ($rack !== null) { $this->rack = $rack; }
        if ($latitude !== null) { $this->latitude = $latitude; }
        if ($longitude !== null) { $this->longitude = $longitude; }
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
        if ($this->region !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($this->region));
            $enc->buf .= $this->region;
        }
        if ($this->datacenter !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->datacenter));
            $enc->buf .= $this->datacenter;
        }
        if ($this->city !== '') {
            $enc->buf .= "\x75";
            $enc->writeVarint(\strlen($this->city));
            $enc->buf .= $this->city;
        }
        if ($this->rack !== 0) {
            $enc->buf .= "\x81";
            $enc->writeVarint($this->rack & 0xFFFFFFFF);
        }
        if ($this->latitude !== 0.0 || pack('g', $this->latitude) !== "\x00\x00\x00\x00") {
            $enc->buf .= "\xb1" . pack('g', $this->latitude);
        }
        if ($this->longitude !== 0.0 || pack('g', $this->longitude) !== "\x00\x00\x00\x00") {
            $enc->buf .= "\xd1" . pack('g', $this->longitude);
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
        $specs[] = ['number' => 1, 'prop' => 'region', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 2, 'prop' => 'datacenter', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 3, 'prop' => 'city', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 4, 'prop' => 'rack', 'kind' => 'uint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 5, 'prop' => 'latitude', 'kind' => 'float32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 6, 'prop' => 'longitude', 'kind' => 'float32', 'omitEmpty' => true, 'nullable' => false];
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
            $this->region = '';
            $this->datacenter = '';
            $this->city = '';
            $this->rack = 0;
            $this->latitude = 0.0;
            $this->longitude = 0.0;
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
                    $this->region = $dec->readString();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->datacenter = $dec->readString();
                    break;
                case 3:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->city = $dec->readString();
                    break;
                case 4:
                    if ($wireType !== 0) { $dec->skipUnknown($wireType); break; }
                    $this->rack = $dec->readUint32();
                    break;
                case 5:
                    if ($wireType !== 8) { $dec->skipUnknown($wireType); break; }
                    $this->latitude = $dec->readFloat32();
                    break;
                case 6:
                    if ($wireType !== 8) { $dec->skipUnknown($wireType); break; }
                    $this->longitude = $dec->readFloat32();
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
            'omnibuf.bench.Location',
            [
                1 => 'region',
                2 => 'datacenter',
                3 => 'city',
                4 => 'rack',
                5 => 'latitude',
                6 => 'longitude',
            ],
            $children,
        );
    }
}
