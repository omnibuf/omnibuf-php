<?php

declare(strict_types=1);

namespace OmniBuf\Generated\Omnibuf\V1;

use OmniBuf\Decoder;
use OmniBuf\Encoder;
use OmniBuf\Opaque;
use OmniBuf\UInt64;
use OmniBuf\Wire;
use OmniBuf\WireType;

final class OpaqueSchemaMeta
{
    /** @var array<int, string> */
    private array $dirty = [];

    /** @var array<int, mixed> */
    private array $dirtyValues = [];

    public function __construct(
        private readonly string $raw,
    ) {}

    public function marshal(): string
    {
        return Wire::spliceFields('', $this->raw, $this->dirty);
    }

    public function marshalAppend(string $prefix = ''): string
    {
        return Wire::spliceFields($prefix, $this->raw, $this->dirty);
    }

    public function getTypeName(): string
    {
        if (\array_key_exists(1, $this->dirtyValues)) {
            return $this->dirtyValues[1];
        }
        return Opaque::getString($this->raw, 0, \strlen($this->raw), 1);
    }

    public function setTypeName(string $value): void
    {
        $this->dirtyValues[1] = $value;
        $enc = new Encoder();
        if ($value !== '') {
            $enc->buf .= "\x35";
            $enc->writeVarint(\strlen($value)); $enc->buf .= $value;
        }
        $this->dirty[1] = $enc->finish();
    }

    /** @return array<int, string> */
    public function getFields(): array
    {
        if (\array_key_exists(2, $this->dirtyValues)) {
            return $this->dirtyValues[2];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 2, WireType::MAP);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(0, 10);
            if (\count($out) + $count > $mapDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireMap count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $k = $mapDec->readUint32();
                $out[$k] = $mapDec->readString();
            }
            $mapDec->assertAtEnd('WireMap');
        }
        return $out;
    }

    /** @param array<int, string> $value */
    public function setFields(array $value): void
    {
        $this->dirtyValues[2] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $entries = $value;
            uksort($entries, static fn(int|string $a, int|string $b): int => UInt64::compare((int) $a, (int) $b));
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
        $this->dirty[2] = $enc->finish();
    }

    /** @return array<int, \OmniBuf\Generated\Omnibuf\V1\SchemaMeta> */
    public function getChildren(): array
    {
        if (\array_key_exists(3, $this->dirtyValues)) {
            return $this->dirtyValues[3];
        }
        $ranges = Opaque::locateAllRawRanges($this->raw, 0, \strlen($this->raw), 3, WireType::MAP);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $dec = new Decoder($this->raw, $s, $e, 0);
            [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(0, 12);
            if (\count($out) + $count > $mapDec->maxElements) {
                throw new \OmniBuf\Exception\DecodeException('omnibuf: WireMap count exceeds maxElements');
            }
            for ($i = 0; $i < $count; $i++) {
                $k = $mapDec->readUint32();
                $out[$k] = (static function () use ($mapDec): \OmniBuf\Generated\Omnibuf\V1\SchemaMeta { $c = new \OmniBuf\Generated\Omnibuf\V1\SchemaMeta(); $c->decodeFrom($mapDec->readSubMessageDecoder(), false); return $c; })();
            }
            $mapDec->assertAtEnd('WireMap');
        }
        return $out;
    }

    /** @param array<int, \OmniBuf\Generated\Omnibuf\V1\SchemaMeta> $value */
    public function setChildren(array $value): void
    {
        $this->dirtyValues[3] = $value;
        $enc = new Encoder();
        if ($value !== []) {
            $entries = $value;
            uksort($entries, static fn(int|string $a, int|string $b): int => UInt64::compare((int) $a, (int) $b));
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
        $this->dirty[3] = $enc->finish();
    }

}
