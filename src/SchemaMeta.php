<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;

/**
 * Field #0 (`omnibuf.v1.SchemaMeta`) inline naming metadata.
 */
final class SchemaMeta
{
    /**
     * @param array<int, string>     $fields
     * @param array<int, SchemaMeta> $children
     */
    public function __construct(
        public string $typeName = '',
        public array $fields = [],
        public array $children = [],
    ) {}

    public function writeTo(Encoder $enc, bool $deterministic = true): void
    {
        $enc->writeByte(Wire::FIELD0_TAG);
        $enc->beginContainer();
        $this->writeBodyTo($enc, $deterministic);
        $enc->endContainer();
    }

    public function encode(bool $deterministic = true): string
    {
        $enc = new Encoder();
        $this->writeTo($enc, $deterministic);
        return $enc->finish();
    }

    private function writeBodyTo(Encoder $enc, bool $deterministic): void
    {
        if ($this->typeName !== '') {
            $enc->writeByte(0x35); // Field #1, WireString
            $enc->writeRawStringPayload($this->typeName);
        }
        if ($this->fields !== []) {
            $keys = array_keys($this->fields);
            foreach ($keys as $k) {
                if ($k < 1 || $k > Wire::MAX_FIELD_NUMBER) {
                    throw new EncodeException("omnibuf: invalid SchemaMeta field number {$k}");
                }
            }
            if ($deterministic) {
                sort($keys, SORT_NUMERIC);
            }
            $enc->writeByte(0x5D); // Field #2, WireMap
            $enc->beginContainer();
            $enc->writeByte(WireType::UINT);
            $enc->writeByte(WireType::STRING);
            $enc->writeVarint(\count($keys));
            foreach ($keys as $k) {
                $enc->writeVarint($k);
                $enc->writeRawStringPayload($this->fields[$k]);
            }
            $enc->endContainer();
        }
        if ($this->children !== []) {
            $keys = array_keys($this->children);
            foreach ($keys as $k) {
                if ($k < 1 || $k > Wire::MAX_FIELD_NUMBER) {
                    throw new EncodeException("omnibuf: invalid SchemaMeta child field number {$k}");
                }
            }
            if ($deterministic) {
                sort($keys, SORT_NUMERIC);
            }
            $enc->writeByte(0x7D); // Field #3, WireMap
            $enc->beginContainer();
            $enc->writeByte(WireType::UINT);
            $enc->writeByte(WireType::SUB_MSG);
            $enc->writeVarint(\count($keys));
            foreach ($keys as $k) {
                $enc->writeVarint($k);
                $enc->beginContainer();
                $this->children[$k]->writeBodyTo($enc, $deterministic);
                $enc->endContainer();
            }
            $enc->endContainer();
        }
    }

    public static function decode(
        string $bytes,
        int $depth = 0,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): self {
        return self::decodeRange($bytes, 0, \strlen($bytes), $depth, $maxDepth, $maxElements);
    }

    public static function decodeRange(
        string $buf,
        int $start,
        int $end,
        int $depth = 0,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): self {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: SchemaMeta nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        $meta = new self();
        $dec = new Decoder($buf, $start, $end, $depth, $maxDepth, $maxElements);
        while ($dec->hasMore()) {
            [$fieldNum, $wireType] = $dec->readTag();
            if ($fieldNum === 0) {
                $dec->readField0();
                continue;
            }
            if ($fieldNum === 1 && $wireType === WireType::STRING) {
                $meta->typeName = $dec->readString();
                continue;
            }
            if ($fieldNum === 2 && $wireType === WireType::MAP) {
                [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(WireType::UINT, WireType::STRING);
                for ($i = 0; $i < $count; $i++) {
                    $k = $mapDec->readVarint();
                    if ($k < 1 || $k > Wire::MAX_FIELD_NUMBER) {
                        throw new DecodeException("omnibuf: SchemaMeta field number {$k} out of range");
                    }
                    $meta->fields[$k] = $mapDec->readString();
                }
                $mapDec->assertAtEnd('SchemaMeta.fields');
                continue;
            }
            if ($fieldNum === 3 && $wireType === WireType::MAP) {
                [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(WireType::UINT, WireType::SUB_MSG);
                for ($i = 0; $i < $count; $i++) {
                    $k = $mapDec->readVarint();
                    if ($k < 1 || $k > Wire::MAX_FIELD_NUMBER) {
                        throw new DecodeException("omnibuf: SchemaMeta child field number {$k} out of range");
                    }
                    [$cStart, $cEnd] = Wire::readLenRange($mapDec->buf, $mapDec->pos, $mapDec->end, 0);
                    $meta->children[$k] = self::decodeRange($mapDec->buf, $cStart, $cEnd, $mapDec->depth + 1, $maxDepth, $maxElements);
                }
                $mapDec->assertAtEnd('SchemaMeta.children');
                continue;
            }
            Wire::validateValue($dec->buf, $dec->pos, $dec->end, $wireType, $dec->depth, $dec->maxDepth, $dec->maxElements, true);
        }
        return $meta;
    }

    public static function merge(?self $base, ?self $next): ?self
    {
        if ($next === null) {
            return $base;
        }
        if ($base === null) {
            $children = [];
            foreach ($next->children as $k => $c) {
                $merged = self::merge(null, $c);
                if ($merged !== null) {
                    $children[$k] = $merged;
                }
            }
            return new self($next->typeName, $next->fields, $children);
        }
        $typeName = $next->typeName !== '' ? $next->typeName : $base->typeName;
        $fields = $base->fields;
        foreach ($next->fields as $k => $v) {
            $fields[$k] = $v;
        }
        $children = [];
        foreach ($base->children as $k => $c) {
            $children[$k] = $c;
        }
        foreach ($next->children as $k => $c) {
            $children[$k] = isset($children[$k]) ? (self::merge($children[$k], $c) ?? $c) : $c;
        }
        return new self($typeName, $fields, $children);
    }
}
