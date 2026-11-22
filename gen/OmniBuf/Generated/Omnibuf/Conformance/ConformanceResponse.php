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

final class ConformanceResponse implements GeneratedMessage
{
    public string $parseError = '';

    public string $serializeError = '';

    public string $runtimeError = '';

    public string $skipped = '';

    public string $omnibufPayload = '';

    public string $jsonPayload = '';

    public function __construct(
        ?string $parseError = null,
        ?string $serializeError = null,
        ?string $runtimeError = null,
        ?string $skipped = null,
        ?string $omnibufPayload = null,
        ?string $jsonPayload = null,
    ) {
        if ($parseError !== null) { $this->parseError = $parseError; }
        if ($serializeError !== null) { $this->serializeError = $serializeError; }
        if ($runtimeError !== null) { $this->runtimeError = $runtimeError; }
        if ($skipped !== null) { $this->skipped = $skipped; }
        if ($omnibufPayload !== null) { $this->omnibufPayload = $omnibufPayload; }
        if ($jsonPayload !== null) { $this->jsonPayload = $jsonPayload; }
    }

    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string
    {
        $enc = new Encoder();
        if ($selfDescribing) {
            self::schemaMeta()->writeTo($enc, true);
        }
        $this->marshalTo($enc, $deterministic);
        return $enc->finish();
    }

    public function marshalTo(Encoder $enc, bool $deterministic = false): void
    {
        if ($this->parseError !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($this->parseError));
            $enc->buf .= $this->parseError;
        }
        if ($this->serializeError !== '') {
            $enc->buf .= "\x55";
            $enc->writeVarint(\strlen($this->serializeError));
            $enc->buf .= $this->serializeError;
        }
        if ($this->runtimeError !== '') {
            $enc->buf .= "\x75";
            $enc->writeVarint(\strlen($this->runtimeError));
            $enc->buf .= $this->runtimeError;
        }
        if ($this->skipped !== '') {
            $enc->buf .= "\x95";
            $enc->writeVarint(\strlen($this->skipped));
            $enc->buf .= $this->skipped;
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
    }

    public static function unmarshalOmnibuf(
        string $data,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): static {
        $msg = new self();
        $msg->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), false);
        return $msg;
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
            $this->parseError = '';
            $this->serializeError = '';
            $this->runtimeError = '';
            $this->skipped = '';
            $this->omnibufPayload = '';
            $this->jsonPayload = '';
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
                    $this->parseError = $dec->readString();
                    break;
                case 2:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->serializeError = $dec->readString();
                    break;
                case 3:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->runtimeError = $dec->readString();
                    break;
                case 4:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->skipped = $dec->readString();
                    break;
                case 5:
                    if ($wireType !== 11) { $dec->skipUnknown($wireType); break; }
                    $this->omnibufPayload = $dec->readBytes();
                    break;
                case 6:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->jsonPayload = $dec->readString();
                    break;
                default:
                    $dec->skipUnknown($wireType);
                    break;
            }
        }
    }

    public static function schemaMeta(): SchemaMeta
    {
        return new SchemaMeta(
            'omnibuf.conformance.ConformanceResponse',
            [
                1 => 'parse_error',
                2 => 'serialize_error',
                3 => 'runtime_error',
                4 => 'skipped',
                5 => 'omnibuf_payload',
                6 => 'json_payload',
            ],
            [
            ],
        );
    }
}
