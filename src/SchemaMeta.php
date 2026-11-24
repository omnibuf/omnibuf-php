<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
use OmniBuf\Internal\ElementBudget;

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

    private function writeBodyTo(Encoder $enc, bool $deterministic, int $depth = 1): void
    {
        if ($depth > Wire::MAX_NESTING_DEPTH) {
            throw new EncodeException("omnibuf: SchemaMeta nesting depth {$depth} exceeds maximum " . Wire::MAX_NESTING_DEPTH);
        }
        if ($this->typeName !== '') {
            $enc->writeByte(0x35); // Field #1, WireString
            $enc->writeRawStringPayload($this->typeName);
        }
        if ($this->fields !== []) {
            if ($depth + 1 > Wire::MAX_NESTING_DEPTH) {
                throw new EncodeException('omnibuf: SchemaMeta fields map nesting depth ' . ($depth + 1) . ' exceeds maximum ' . Wire::MAX_NESTING_DEPTH);
            }
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
            if ($depth + 1 > Wire::MAX_NESTING_DEPTH) {
                throw new EncodeException('omnibuf: SchemaMeta children map nesting depth ' . ($depth + 1) . ' exceeds maximum ' . Wire::MAX_NESTING_DEPTH);
            }
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
                $this->children[$k]->writeBodyTo($enc, $deterministic, $depth + 2);
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
        ?ElementBudget $budget = null,
    ): self {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: SchemaMeta nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        $meta = new self();
        $dec = new Decoder($buf, $start, $end, $depth, $maxDepth, $maxElements, $budget);
        while ($dec->hasMore()) {
            [$fieldNum, $wireType] = $dec->readTag();
            if ($fieldNum === 0) {
                throw new DecodeException('omnibuf: invalid nested Field #0 inside SchemaMeta');
            }
            if ($fieldNum === 1) {
                if ($wireType !== WireType::STRING) {
                    throw new DecodeException("omnibuf: SchemaMeta field #1 (type_name) must be WireString, got {$wireType}");
                }
                $meta->typeName = $dec->readString();
                continue;
            }
            if ($fieldNum === 2) {
                if ($wireType !== WireType::MAP) {
                    throw new DecodeException("omnibuf: SchemaMeta field #2 (fields) must be WireMap, got {$wireType}");
                }
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
            if ($fieldNum === 3) {
                if ($wireType !== WireType::MAP) {
                    throw new DecodeException("omnibuf: SchemaMeta field #3 (children) must be WireMap, got {$wireType}");
                }
                [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(WireType::UINT, WireType::SUB_MSG);
                /** @var array<int, SchemaMeta> $localChildren */
                $localChildren = [];
                for ($i = 0; $i < $count; $i++) {
                    $k = $mapDec->readVarint();
                    if ($k < 1 || $k > Wire::MAX_FIELD_NUMBER) {
                        throw new DecodeException("omnibuf: SchemaMeta child field number {$k} out of range");
                    }
                    [$cStart, $cEnd] = Wire::readLenRange($mapDec->buf, $mapDec->pos, $mapDec->end, 0);
                    // Duplicate key inside a single WireMap replaces prior entry (Spec 6.6).
                    $localChildren[$k] = self::decodeRange($mapDec->buf, $cStart, $cEnd, $mapDec->depth + 1, $maxDepth, $maxElements, $dec->budget);
                }
                $mapDec->assertAtEnd('SchemaMeta.children');
                // Recursively merge across separate children WireMap fields (Spec 8.2).
                foreach ($localChildren as $k => $childMeta) {
                    $meta->children[$k] = isset($meta->children[$k])
                        ? (self::merge($meta->children[$k], $childMeta) ?? $childMeta)
                        : $childMeta;
                }
                continue;
            }
            Wire::validateValue($dec->buf, $dec->pos, $dec->end, $wireType, $dec->depth, $dec->maxDepth, $dec->maxElements, true, $dec->budget);
        }
        return $meta;
    }

    public static function merge(?self $base, ?self $next, int $depth = 0): ?self
    {
        if ($depth > Wire::MAX_NESTING_DEPTH) {
            throw new DecodeException("omnibuf: SchemaMeta merge nesting depth {$depth} exceeds maximum " . Wire::MAX_NESTING_DEPTH);
        }
        if ($next === null) {
            return $base;
        }
        if ($base === null) {
            $children = [];
            foreach ($next->children as $k => $c) {
                $merged = self::merge(null, $c, $depth + 1);
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
            $children[$k] = isset($children[$k]) ? (self::merge($children[$k], $c, $depth + 1) ?? $c) : $c;
        }
        return new self($typeName, $fields, $children);
    }
}
