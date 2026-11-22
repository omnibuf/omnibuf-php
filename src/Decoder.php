<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;

/**
 * Low-level cursor decoder over an OmniBuf binary string slice `[pos..end]`.
 */
final class Decoder
{
    public function __construct(
        public readonly string $buf,
        public int $pos = 0,
        public int $end = -1,
        public readonly int $depth = 0,
        public readonly int $maxDepth = Wire::MAX_NESTING_DEPTH,
        public readonly int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ) {
        if ($this->end < 0) {
            $this->end = \strlen($this->buf);
        }
        if ($this->depth > $this->maxDepth) {
            throw new DecodeException("omnibuf: nesting depth {$this->depth} exceeds maximum {$this->maxDepth}");
        }
    }

    public function hasMore(): bool
    {
        return $this->pos < $this->end;
    }

    public function remaining(): int
    {
        return $this->end - $this->pos;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function readTag(): array
    {
        return Wire::decodeTag($this->buf, $this->pos, $this->end);
    }

    public function readVarint(): int
    {
        return Wire::decodeVarint($this->buf, $this->pos, $this->end);
    }

    public function readUint32(): int
    {
        return Wire::decodeVarint($this->buf, $this->pos, $this->end) & 0xFFFFFFFF;
    }

    public function readUint64(): int
    {
        return Wire::decodeVarint($this->buf, $this->pos, $this->end);
    }

    public function readSint32(): int
    {
        $u = Wire::decodeVarint($this->buf, $this->pos, $this->end);
        $n = (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
        return ($n << 32) >> 32;
    }

    public function readSint64(): int
    {
        $u = Wire::decodeVarint($this->buf, $this->pos, $this->end);
        return (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
    }

    public function readFixed32(): int
    {
        if ($this->end - $this->pos < 4) {
            throw new DecodeException("omnibuf: truncated fixed32 at offset {$this->pos}");
        }
        $p = $this->pos;
        $val = \ord($this->buf[$p])
            | (\ord($this->buf[$p + 1]) << 8)
            | (\ord($this->buf[$p + 2]) << 16)
            | (\ord($this->buf[$p + 3]) << 24);
        $this->pos += 4;
        return $val & 0xFFFFFFFF;
    }

    public function readSfixed32(): int
    {
        $u = $this->readFixed32();
        return ($u << 32) >> 32;
    }

    public function readFixed64(): int
    {
        if ($this->end - $this->pos < 8) {
            throw new DecodeException("omnibuf: truncated fixed64 at offset {$this->pos}");
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $this->buf, $this->pos);
        $this->pos += 8;
        return $unpacked[1];
    }

    public function readSfixed64(): int
    {
        return $this->readFixed64();
    }

    public function readFloat32(): float
    {
        if ($this->end - $this->pos < 4) {
            throw new DecodeException("omnibuf: truncated float32 at offset {$this->pos}");
        }
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('g', $this->buf, $this->pos);
        $this->pos += 4;
        return $unpacked[1];
    }

    public function readFloat64(): float
    {
        if ($this->end - $this->pos < 8) {
            throw new DecodeException("omnibuf: truncated float64 at offset {$this->pos}");
        }
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('e', $this->buf, $this->pos);
        $this->pos += 8;
        return $unpacked[1];
    }

    public function readBoolByte(): bool
    {
        if ($this->pos >= $this->end) {
            throw new DecodeException("omnibuf: truncated collection bool at offset {$this->pos}");
        }
        $b = \ord($this->buf[$this->pos++]);
        if ($b === 0) {
            return false;
        }
        if ($b === 1) {
            return true;
        }
        throw new DecodeException("omnibuf: invalid collection bool byte {$b}");
    }

    public function readString(): string
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 0);
        if ($bStart === $bEnd) {
            return '';
        }
        $s = substr($this->buf, $bStart, $bEnd - $bStart);
        if (!Wire::isValidUtf8($s)) {
            throw new DecodeException("omnibuf: invalid UTF-8 string at offset {$bStart}");
        }
        return $s;
    }

    public function readBytes(): string
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 0);
        if ($bStart === $bEnd) {
            return '';
        }
        return substr($this->buf, $bStart, $bEnd - $bStart);
    }

    /**
     * Reads a length-prefixed submessage and returns a child Decoder with `depth + 1`.
     */
    public function readSubMessageDecoder(): self
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 0);
        return new self($this->buf, $bStart, $bEnd, $this->depth + 1, $this->maxDepth, $this->maxElements);
    }

    /**
     * Reads and validates Field #0 SchemaMeta.
     */
    public function readField0(): SchemaMeta
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 0);
        return SchemaMeta::decodeRange($this->buf, $bStart, $bEnd, $this->depth + 1, $this->maxDepth, $this->maxElements);
    }

    /**
     * Reads a WireArray header and validates element type, count bounds, and fixed-width alignment.
     * Returns `[bodyDecoder, elemType, count]`.
     *
     * @return array{0: self, 1: int, 2: int}
     */
    public function readArrayHeader(?int $expectedElemType = null): array
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 2);
        $childDepth = $this->depth + 1;
        if ($childDepth > $this->maxDepth) {
            throw new DecodeException("omnibuf: array nesting depth {$childDepth} exceeds maximum {$this->maxDepth}");
        }
        $elemType = \ord($this->buf[$bStart]);
        if (!WireType::isValidCollectionType($elemType)) {
            throw new DecodeException("omnibuf: invalid WireArray element type {$elemType}");
        }
        if ($expectedElemType !== null && $elemType !== $expectedElemType) {
            throw new DecodeException(
                'omnibuf: WireArray element type mismatch: expected '
                . WireType::name($expectedElemType) . ', got ' . WireType::name($elemType),
            );
        }
        $p = $bStart + 1;
        $count = Wire::decodeVarint($this->buf, $p, $bEnd);
        if ($count < 0) {
            throw new DecodeException('omnibuf: WireArray count overflow');
        }
        $rem = $bEnd - $p;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireArray with count=0 has {$rem} trailing bytes");
            }
            return [new self($this->buf, $p, $bEnd, $childDepth, $this->maxDepth, $this->maxElements), $elemType, 0];
        }
        $fixedWidth = WireType::collectionFixedWidth($elemType);
        if ($fixedWidth > 0) {
            if ($rem % $fixedWidth !== 0 || $count !== intdiv($rem, $fixedWidth)) {
                throw new DecodeException("omnibuf: WireArray fixed-width count {$count} mismatches {$rem} remaining bytes");
            }
        } elseif ($count > $rem) {
            throw new DecodeException("omnibuf: WireArray count {$count} exceeds remaining {$rem} bytes");
        }
        if ($count > $this->maxElements) {
            throw new DecodeException("omnibuf: WireArray count {$count} exceeds maxElements {$this->maxElements}");
        }
        return [new self($this->buf, $p, $bEnd, $childDepth, $this->maxDepth, $this->maxElements), $elemType, $count];
    }

    /**
     * Reads a WireMap header and validates key/value types and count bounds.
     * Returns `[bodyDecoder, keyType, valType, count]`.
     *
     * @return array{0: self, 1: int, 2: int, 3: int}
     */
    public function readMapHeader(?int $expectedKeyType = null, ?int $expectedValType = null): array
    {
        [$bStart, $bEnd] = Wire::readLenRange($this->buf, $this->pos, $this->end, 3);
        $childDepth = $this->depth + 1;
        if ($childDepth > $this->maxDepth) {
            throw new DecodeException("omnibuf: map nesting depth {$childDepth} exceeds maximum {$this->maxDepth}");
        }
        $keyType = \ord($this->buf[$bStart]);
        $valType = \ord($this->buf[$bStart + 1]);
        if (!WireType::isValidKeyType($keyType)) {
            throw new DecodeException("omnibuf: invalid WireMap key type {$keyType}");
        }
        if (!WireType::isValidCollectionType($valType)) {
            throw new DecodeException("omnibuf: invalid WireMap value type {$valType}");
        }
        if ($expectedKeyType !== null && $keyType !== $expectedKeyType) {
            throw new DecodeException(
                'omnibuf: WireMap key type mismatch: expected '
                . WireType::name($expectedKeyType) . ', got ' . WireType::name($keyType),
            );
        }
        if ($expectedValType !== null && $valType !== $expectedValType) {
            throw new DecodeException(
                'omnibuf: WireMap value type mismatch: expected '
                . WireType::name($expectedValType) . ', got ' . WireType::name($valType),
            );
        }
        $p = $bStart + 2;
        $count = Wire::decodeVarint($this->buf, $p, $bEnd);
        if ($count < 0) {
            throw new DecodeException('omnibuf: WireMap count overflow');
        }
        $rem = $bEnd - $p;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireMap with count=0 has {$rem} trailing bytes");
            }
            return [new self($this->buf, $p, $bEnd, $childDepth, $this->maxDepth, $this->maxElements), $keyType, $valType, 0];
        }
        if ($count > intdiv($rem, 2)) {
            throw new DecodeException("omnibuf: WireMap count {$count} exceeds remaining {$rem} bytes / 2");
        }
        if ($count > $this->maxElements) {
            throw new DecodeException("omnibuf: WireMap count {$count} exceeds maxElements {$this->maxElements}");
        }
        return [new self($this->buf, $p, $bEnd, $childDepth, $this->maxDepth, $this->maxElements), $keyType, $valType, $count];
    }

    /**
     * Validates and skips an unknown or wire-type-mismatched field value.
     */
    public function skipUnknown(int $wireType): void
    {
        Wire::validateValue($this->buf, $this->pos, $this->end, $wireType, $this->depth, $this->maxDepth, $this->maxElements, false);
    }

    /**
     * Asserts that the decoder has reached the exact end of its container.
     */
    public function assertAtEnd(string $context = 'container'): void
    {
        if ($this->pos !== $this->end) {
            throw new DecodeException("omnibuf: {$context} has " . ($this->end - $this->pos) . " trailing bytes at offset {$this->pos}");
        }
    }
}
