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

final class ConformanceRequest implements GeneratedMessage
{
    private static ?int $nativeSchemaId = null;
    private static bool $nativeSchemaChecked = false;

    public string $testName = '';

    public string $messageType = '';

    public int $tier = 0;

    public int $requestedOutputFormat = 0;

    public string $omnibufPayload = '';

    public string $jsonPayload = '';

    public bool $selfDescribing = false;

    public bool $testOpaqueSplice = false;

    public bool $testOpaqueSpliceShrink = false;

    public function __construct(
        ?string $testName = null,
        ?string $messageType = null,
        ?int $tier = null,
        ?int $requestedOutputFormat = null,
        ?string $omnibufPayload = null,
        ?string $jsonPayload = null,
        ?bool $selfDescribing = null,
        ?bool $testOpaqueSplice = null,
        ?bool $testOpaqueSpliceShrink = null,
    ) {
        if ($testName !== null) { $this->testName = $testName; }
        if ($messageType !== null) { $this->messageType = $messageType; }
        if ($tier !== null) { $this->tier = $tier; }
        if ($requestedOutputFormat !== null) { $this->requestedOutputFormat = $requestedOutputFormat; }
        if ($omnibufPayload !== null) { $this->omnibufPayload = $omnibufPayload; }
        if ($jsonPayload !== null) { $this->jsonPayload = $jsonPayload; }
        if ($selfDescribing !== null) { $this->selfDescribing = $selfDescribing; }
        if ($testOpaqueSplice !== null) { $this->testOpaqueSplice = $testOpaqueSplice; }
        if ($testOpaqueSpliceShrink !== null) { $this->testOpaqueSpliceShrink = $testOpaqueSpliceShrink; }
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
        if ($this->testName !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($this->testName));
            $enc->buf .= $this->testName;
        }
        if ($this->messageType !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->messageType));
            $enc->buf .= $this->messageType;
        }
        if ($this->tier !== 0) {
            $enc->buf .= "\x63";
            $n = ($this->tier << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->requestedOutputFormat !== 0) {
            $enc->buf .= "\x83";
            $n = ($this->requestedOutputFormat << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->omnibufPayload !== '') {
            $enc->buf .= "\xb7";
            $enc->writeVarint(\strlen($this->omnibufPayload));
            $enc->buf .= $this->omnibufPayload;
        }
        if ($this->jsonPayload !== '') {
            $enc->buf .= "\xd5";
            $enc->writeVarint(\strlen($this->jsonPayload));
            $enc->buf .= $this->jsonPayload;
        }
        if ($this->selfDescribing) {
            $enc->buf .= "\xe7";
        }
        if ($this->testOpaqueSplice) {
            $enc->buf .= "\x0e\x02";
        }
        if ($this->testOpaqueSpliceShrink) {
            $enc->buf .= "\x4e\x02";
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
        $specs[] = ['number' => 1, 'prop' => 'testName', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 2, 'prop' => 'messageType', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 3, 'prop' => 'tier', 'kind' => 'sint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 4, 'prop' => 'requestedOutputFormat', 'kind' => 'sint32', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 5, 'prop' => 'omnibufPayload', 'kind' => 'bytes', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 6, 'prop' => 'jsonPayload', 'kind' => 'string', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 7, 'prop' => 'selfDescribing', 'kind' => 'bool', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 8, 'prop' => 'testOpaqueSplice', 'kind' => 'bool', 'omitEmpty' => true, 'nullable' => false];
        $specs[] = ['number' => 9, 'prop' => 'testOpaqueSpliceShrink', 'kind' => 'bool', 'omitEmpty' => true, 'nullable' => false];
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
            $this->testName = '';
            $this->messageType = '';
            $this->tier = 0;
            $this->requestedOutputFormat = 0;
            $this->omnibufPayload = '';
            $this->jsonPayload = '';
            $this->selfDescribing = false;
            $this->testOpaqueSplice = false;
            $this->testOpaqueSpliceShrink = false;
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
                    $this->testName = $dec->readString();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->messageType = $dec->readString();
                    break;
                case 3:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->tier = $dec->readSint32();
                    break;
                case 4:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->requestedOutputFormat = $dec->readSint32();
                    break;
                case 5:
                    if ($wireType !== 11) { $dec->skipUnknown($wireType); break; }
                    $this->omnibufPayload = $dec->readBytes();
                    break;
                case 6:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->jsonPayload = $dec->readString();
                    break;
                case 7:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->selfDescribing = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->selfDescribing = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 8:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->testOpaqueSplice = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->testOpaqueSplice = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 9:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->testOpaqueSpliceShrink = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->testOpaqueSpliceShrink = true;
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
            'omnibuf.conformance.ConformanceRequest',
            [
                1 => 'test_name',
                2 => 'message_type',
                3 => 'tier',
                4 => 'requested_output_format',
                5 => 'omnibuf_payload',
                6 => 'json_payload',
                7 => 'self_describing',
                8 => 'test_opaque_splice',
                9 => 'test_opaque_splice_shrink',
            ],
            $children,
        );
    }
}
