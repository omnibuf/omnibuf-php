<?php

declare(strict_types=1);

namespace OmniBuf;

use OmniBuf\Exception\DecodeException;
use OmniBuf\Exception\EncodeException;
use OmniBuf\Internal\BlindBytes;
use OmniBuf\Internal\BlindObject;
use OmniBuf\Internal\BlindUInt64;
use OmniBuf\Internal\ElementBudget;

/**
 * Tier 4 schema-less blind decoder (`decode` and `decodeJson`) with Field #0 SchemaMeta support.
 */
final class BlindDecoder
{
    /**
     * Decodes any OmniBuf packet without a schema into a PHP associative array.
     *
     * @return array<string, mixed>
     */
    public static function decode(
        string $packet,
        bool $bytesAsBase64 = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): array {
        $budget = new ElementBudget($maxElements);
        $obj = self::decodeMessageInternal($packet, [[0, \strlen($packet)]], null, 0, $maxDepth, $maxElements, false, $budget);
        /** @var array<string, mixed> $unwrapped */
        $unwrapped = self::unwrapToPhp($obj, $bytesAsBase64);
        return $unwrapped;
    }

    /**
     * Decodes any OmniBuf packet without a schema and formats it as deterministic JSON.
     */
    public static function decodeJson(
        string $packet,
        bool $allowNan = false,
        bool $fallbackOnNonFinite = false,
        int $indent = 0,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): string {
        $budget = new ElementBudget($maxElements);
        $obj = self::decodeMessageInternal($packet, [[0, \strlen($packet)]], null, 0, $maxDepth, $maxElements, true, $budget);
        $hasNonFinite = false;
        $step = $indent > 0 ? str_repeat(' ', min(10, $indent)) : '';
        $json = self::serializeJson($obj, $step, '', $hasNonFinite);
        if ($hasNonFinite) {
            if ($fallbackOnNonFinite) {
                return '{}';
            }
            if (!$allowNan) {
                throw new EncodeException('omnibuf: non-finite float (NaN or Infinity) cannot be represented in JSON');
            }
        }
        return $json;
    }

    /**
     * Decodes one or more concatenated message slices (for recursive submessage merging) into an internal object.
     *
     * @param list<array{0: int, 1: int}> $slices
     */
    private static function decodeMessageInternal(
        string $buf,
        array $slices,
        ?SchemaMeta $inheritedMeta,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $roundFloat32,
        ElementBudget $budget,
    ): BlindObject {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: nesting depth {$depth} exceeds maximum {$maxDepth}");
        }

        $meta = $inheritedMeta;
        /** @var array<int, array{kind: string, wireType: int, value: mixed}> $fields */
        $fields = [];

        foreach ($slices as [$start, $end]) {
            $dec = new Decoder($buf, $start, $end, $depth, $maxDepth, $maxElements, $budget);
            while ($dec->hasMore()) {
                [$fieldNum, $wireType] = $dec->readTag();
                if ($fieldNum === 0) {
                    $blockMeta = $dec->readField0();
                    $meta = SchemaMeta::merge($meta, $blockMeta);
                    continue;
                }
                $normWt = $wireType === WireType::BOOL_TRUE ? WireType::BOOL_FALSE : $wireType;
                if (isset($fields[$fieldNum]) && $fields[$fieldNum]['wireType'] !== $normWt) {
                    throw new DecodeException(
                        "omnibuf: field #{$fieldNum} wire type changed from "
                        . WireType::name($fields[$fieldNum]['wireType']) . ' to ' . WireType::name($normWt),
                    );
                }
                switch ($wireType) {
                    case WireType::UINT:
                        $u = $dec->readUint64();
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $u >= 0 ? $u : new BlindUInt64(UInt64::toDecimalString($u))];
                        break;
                    case WireType::SINT:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readSint64()];
                        break;
                    case WireType::BOOL_FALSE:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => false];
                        break;
                    case WireType::BOOL_TRUE:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => true];
                        break;
                    case WireType::FIXED32:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readFixed32()];
                        break;
                    case WireType::FIXED64:
                        $u = $dec->readFixed64();
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $u >= 0 ? $u : new BlindUInt64(UInt64::toDecimalString($u))];
                        break;
                    case WireType::SFIXED32:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readSfixed32()];
                        break;
                    case WireType::SFIXED64:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readSfixed64()];
                        break;
                    case WireType::FLOAT32:
                        $f = $dec->readFloat32();
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $roundFloat32 ? Wire::roundFloat32Shortest($f) : $f];
                        break;
                    case WireType::FLOAT64:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readFloat64()];
                        break;
                    case WireType::STRING:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => $dec->readString()];
                        break;
                    case WireType::BYTES:
                        $fields[$fieldNum] = ['kind' => 'scalar', 'wireType' => $normWt, 'value' => new BlindBytes($dec->readBytes())];
                        break;
                    case WireType::SUB_MSG:
                        $subRange = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 0);
                        if (isset($fields[$fieldNum])) {
                            $fields[$fieldNum]['value'][] = $subRange;
                        } else {
                            $fields[$fieldNum] = ['kind' => 'submsg', 'wireType' => $normWt, 'value' => [$subRange]];
                        }
                        break;
                    case WireType::ARRAY:
                        $arrRange = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 2);
                        if (isset($fields[$fieldNum])) {
                            $fields[$fieldNum]['value'][] = $arrRange;
                        } else {
                            $fields[$fieldNum] = ['kind' => 'array', 'wireType' => $normWt, 'value' => [$arrRange]];
                        }
                        break;
                    case WireType::MAP:
                        $mapRange = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 3);
                        if (isset($fields[$fieldNum])) {
                            $fields[$fieldNum]['value'][] = $mapRange;
                        } else {
                            $fields[$fieldNum] = ['kind' => 'map', 'wireType' => $normWt, 'value' => [$mapRange]];
                        }
                        break;
                    case WireType::EXTENDED:
                        [$eStart, $_eEnd] = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 1);
                        $extType = \ord($dec->buf[$eStart]);
                        throw new DecodeException(\sprintf('omnibuf: unrecognized WireExtended subtype 0x%02x', $extType));
                    default:
                        throw new DecodeException("omnibuf: invalid wire type {$wireType}");
                }
            }
        }

        // Resolve field names after all Field #0 blocks have been merged
        ksort($fields, SORT_NUMERIC);
        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($fields as $fieldNum => $entry) {
            $childMeta = $meta?->children[$fieldNum] ?? null;
            $val = match ($entry['kind']) {
                'submsg' => self::decodeMessageInternal($buf, $entry['value'], $childMeta, $depth + 1, $maxDepth, $maxElements, $roundFloat32, $budget),
                'array' => self::decodeArrayOccurrences($buf, $entry['value'], $childMeta, $depth + 1, $maxDepth, $maxElements, $roundFloat32, $budget),
                'map' => self::decodeMapOccurrences($buf, $entry['value'], $childMeta, $depth + 1, $maxDepth, $maxElements, $roundFloat32, $budget),
                default => $entry['value'],
            };

            $name = $meta?->fields[$fieldNum] ?? '';
            $key = $name !== '' ? $name : "field_{$fieldNum}";
            if (\array_key_exists($key, $out)) {
                throw new DecodeException("omnibuf: Field #0 name collision on '{$key}' for field #{$fieldNum}");
            }
            $out[$key] = $val;
        }

        return new BlindObject($out);
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<mixed>
     */
    private static function decodeArrayOccurrences(
        string $buf,
        array $ranges,
        ?SchemaMeta $childMeta,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $roundFloat32,
        ElementBudget $budget,
    ): array {
        $result = [];
        $expectedElemType = null;
        $sharedInnerElemType = null;
        foreach ($ranges as [$start, $end]) {
            $et = self::decodeSingleArrayBody(
                $buf,
                $start,
                $end,
                $childMeta,
                $depth,
                $maxDepth,
                $maxElements,
                $roundFloat32,
                $budget,
                $result,
                $sharedInnerElemType,
            );
            if ($expectedElemType === null) {
                $expectedElemType = $et;
            } elseif ($expectedElemType !== $et) {
                throw new DecodeException("omnibuf: WireArray element type changed from {$expectedElemType} to {$et}");
            }
        }
        return $result;
    }

    /**
     * @param list<mixed> $result
     */
    private static function decodeSingleArrayBody(
        string $buf,
        int $start,
        int $end,
        ?SchemaMeta $childMeta,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $roundFloat32,
        ElementBudget $budget,
        array &$result,
        ?int &$innerArrayElemType = null,
    ): int {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: array nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        if ($end - $start < 2) {
            throw new DecodeException('omnibuf: truncated WireArray header');
        }
        $elemType = \ord($buf[$start]);
        if (!WireType::isValidCollectionType($elemType)) {
            throw new DecodeException("omnibuf: invalid WireArray element type {$elemType}");
        }
        $p = $start + 1;
        $count = Wire::decodeVarint($buf, $p, $end);
        if ($count < 0) {
            throw new DecodeException('omnibuf: WireArray count overflow');
        }
        $rem = $end - $p;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireArray with count=0 has {$rem} trailing bytes");
            }
            return $elemType;
        }
        $fixedWidth = WireType::collectionFixedWidth($elemType);
        if ($fixedWidth > 0) {
            if ($rem % $fixedWidth !== 0 || $count !== intdiv($rem, $fixedWidth)) {
                throw new DecodeException("omnibuf: WireArray fixed-width count {$count} mismatches {$rem} bytes");
            }
        } elseif ($count > $rem) {
            throw new DecodeException("omnibuf: WireArray count {$count} exceeds remaining {$rem} bytes");
        }
        $budget->consume($count, 'WireArray');

        $dec = new Decoder($buf, $p, $end, $depth, $maxDepth, $maxElements, $budget);
        for ($i = 0; $i < $count; $i++) {
            if ($elemType === WireType::ARRAY) {
                $range = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 2);
                $arr = [];
                $subInner = null;
                $innerEt = self::decodeSingleArrayBody($dec->buf, $range[0], $range[1], $childMeta, $dec->depth + 1, $dec->maxDepth, $dec->maxElements, $roundFloat32, $budget, $arr, $subInner);
                if ($innerArrayElemType === null) {
                    $innerArrayElemType = $innerEt;
                } elseif ($innerArrayElemType !== $innerEt) {
                    throw new DecodeException("omnibuf: nested WireArray inner element type conflict ({$innerArrayElemType} vs {$innerEt})");
                }
                $result[] = $arr;
            } else {
                $result[] = self::decodeCollectionValue($dec, $elemType, $childMeta, $roundFloat32);
            }
        }
        $dec->assertAtEnd('WireArray');
        return $elemType;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private static function decodeMapOccurrences(
        string $buf,
        array $ranges,
        ?SchemaMeta $childMeta,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $roundFloat32,
        ElementBudget $budget,
    ): BlindObject {
        /** @var array<string, mixed> $result */
        $result = [];
        $expectedKeyType = null;
        $expectedValType = null;
        foreach ($ranges as [$start, $end]) {
            [$kt, $vt] = self::decodeSingleMapBody($buf, $start, $end, $childMeta, $depth, $maxDepth, $maxElements, $roundFloat32, $budget, $result);
            if ($expectedKeyType === null) {
                $expectedKeyType = $kt;
                $expectedValType = $vt;
            } elseif ($expectedKeyType !== $kt || $expectedValType !== $vt) {
                throw new DecodeException("omnibuf: WireMap types changed from ({$expectedKeyType},{$expectedValType}) to ({$kt},{$vt})");
            }
        }
        return new BlindObject($result);
    }

    /**
     * @param array<string, mixed> $result
     * @return array{0: int, 1: int}
     */
    private static function decodeSingleMapBody(
        string $buf,
        int $start,
        int $end,
        ?SchemaMeta $childMeta,
        int $depth,
        int $maxDepth,
        int $maxElements,
        bool $roundFloat32,
        ElementBudget $budget,
        array &$result,
    ): array {
        if ($depth > $maxDepth) {
            throw new DecodeException("omnibuf: map nesting depth {$depth} exceeds maximum {$maxDepth}");
        }
        if ($end - $start < 3) {
            throw new DecodeException('omnibuf: truncated WireMap header');
        }
        $keyType = \ord($buf[$start]);
        $valType = \ord($buf[$start + 1]);
        if (!WireType::isValidKeyType($keyType)) {
            throw new DecodeException("omnibuf: invalid WireMap key type {$keyType}");
        }
        if (!WireType::isValidCollectionType($valType)) {
            throw new DecodeException("omnibuf: invalid WireMap value type {$valType}");
        }
        $p = $start + 2;
        $count = Wire::decodeVarint($buf, $p, $end);
        if ($count < 0) {
            throw new DecodeException('omnibuf: WireMap count overflow');
        }
        $rem = $end - $p;
        if ($count === 0) {
            if ($rem !== 0) {
                throw new DecodeException("omnibuf: WireMap with count=0 has {$rem} trailing bytes");
            }
            return [$keyType, $valType];
        }
        if ($count > intdiv($rem, 2)) {
            throw new DecodeException("omnibuf: WireMap count {$count} exceeds remaining {$rem} bytes / 2");
        }
        $budget->consume($count, 'WireMap');

        $dec = new Decoder($buf, $p, $end, $depth, $maxDepth, $maxElements, $budget);
        for ($i = 0; $i < $count; $i++) {
            $key = self::decodeMapKeyString($dec, $keyType);
            $result[$key] = self::decodeCollectionValue($dec, $valType, $childMeta, $roundFloat32);
        }
        $dec->assertAtEnd('WireMap');
        return [$keyType, $valType];
    }

    private static function decodeMapKeyString(Decoder $dec, int $keyType): string
    {
        return match ($keyType) {
            WireType::UINT => UInt64::toDecimalString($dec->readUint64()),
            WireType::SINT => (string) $dec->readSint64(),
            WireType::BOOL_FALSE => $dec->readBoolByte() ? 'true' : 'false',
            WireType::FIXED32 => (string) $dec->readFixed32(),
            WireType::FIXED64 => UInt64::toDecimalString($dec->readFixed64()),
            WireType::SFIXED32 => (string) $dec->readSfixed32(),
            WireType::SFIXED64 => (string) $dec->readSfixed64(),
            WireType::STRING => $dec->readString(),
            default => throw new DecodeException("omnibuf: invalid WireMap key type {$keyType}"),
        };
    }

    private static function decodeCollectionValue(
        Decoder $dec,
        int $valType,
        ?SchemaMeta $childMeta,
        bool $roundFloat32,
    ): mixed {
        switch ($valType) {
            case WireType::UINT:
                $u = $dec->readUint64();
                return $u >= 0 ? $u : new BlindUInt64(UInt64::toDecimalString($u));
            case WireType::SINT:
                return $dec->readSint64();
            case WireType::BOOL_FALSE:
                return $dec->readBoolByte();
            case WireType::FIXED32:
                return $dec->readFixed32();
            case WireType::FIXED64:
                $u = $dec->readFixed64();
                return $u >= 0 ? $u : new BlindUInt64(UInt64::toDecimalString($u));
            case WireType::SFIXED32:
                return $dec->readSfixed32();
            case WireType::SFIXED64:
                return $dec->readSfixed64();
            case WireType::FLOAT32:
                $f = $dec->readFloat32();
                return $roundFloat32 ? Wire::roundFloat32Shortest($f) : $f;
            case WireType::FLOAT64:
                return $dec->readFloat64();
            case WireType::STRING:
                return $dec->readString();
            case WireType::BYTES:
                return new BlindBytes($dec->readBytes());
            case WireType::SUB_MSG:
                $range = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 0);
                return self::decodeMessageInternal($dec->buf, [$range], $childMeta, $dec->depth + 1, $dec->maxDepth, $dec->maxElements, $roundFloat32, $dec->budget);
            case WireType::ARRAY:
                $range = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 2);
                $arr = [];
                self::decodeSingleArrayBody($dec->buf, $range[0], $range[1], $childMeta, $dec->depth + 1, $dec->maxDepth, $dec->maxElements, $roundFloat32, $dec->budget, $arr);
                return $arr;
            case WireType::MAP:
                $range = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 3);
                $map = [];
                self::decodeSingleMapBody($dec->buf, $range[0], $range[1], $childMeta, $dec->depth + 1, $dec->maxDepth, $dec->maxElements, $roundFloat32, $dec->budget, $map);
                return new BlindObject($map);
            case WireType::EXTENDED:
                [$eStart, $_eEnd] = Wire::readLenRange($dec->buf, $dec->pos, $dec->end, 1);
                $extType = \ord($dec->buf[$eStart]);
                throw new DecodeException(\sprintf('omnibuf: unrecognized WireExtended subtype 0x%02x', $extType));
            default:
                throw new DecodeException("omnibuf: invalid collection wire type {$valType}");
        }
    }

    private static function unwrapToPhp(mixed $val, bool $bytesAsBase64): mixed
    {
        if ($val instanceof BlindObject) {
            $out = [];
            foreach ($val->fields as $k => $v) {
                $out[(string) $k] = self::unwrapToPhp($v, $bytesAsBase64);
            }
            return $out;
        }
        if ($val instanceof BlindUInt64) {
            return $val->decimal;
        }
        if ($val instanceof BlindBytes) {
            return $bytesAsBase64 ? base64_encode($val->bytes) : $val->bytes;
        }
        if (\is_array($val)) {
            $out = [];
            foreach ($val as $item) {
                $out[] = self::unwrapToPhp($item, $bytesAsBase64);
            }
            return $out;
        }
        return $val;
    }

    private static function serializeJson(mixed $val, string $step, string $currentIndent, bool &$hasNonFinite): string
    {
        if (\is_int($val)) {
            return (string) $val;
        }
        if (\is_float($val)) {
            if (!is_finite($val)) {
                $hasNonFinite = true;
                return 'null';
            }
            if ($val === 0.0) {
                return (pack('e', $val) === "\x00\x00\x00\x00\x00\x00\x00\x80") ? '-0' : '0';
            }
            $encoded = json_encode($val);
            return $encoded !== false ? $encoded : '0';
        }
        if (\is_bool($val)) {
            return $val ? 'true' : 'false';
        }
        if (\is_string($val)) {
            $encoded = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : '""';
        }
        if ($val === null) {
            return 'null';
        }
        if ($val instanceof BlindUInt64) {
            return $val->decimal;
        }
        if ($val instanceof BlindBytes) {
            return '"' . base64_encode($val->bytes) . '"';
        }
        if ($val instanceof BlindObject) {
            $map = $val->fields;
            if ($map === []) {
                return '{}';
            }
            $keys = array_map('strval', array_keys($map));
            usort($keys, 'strcmp');
            $parts = [];
            $nextIndent = $step !== '' ? $currentIndent . $step : '';
            foreach ($keys as $k) {
                $kJson = json_encode($k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $vJson = self::serializeJson($map[$k], $step, $nextIndent, $hasNonFinite);
                $parts[] = $step !== '' ? "{$nextIndent}{$kJson}: {$vJson}" : "{$kJson}:{$vJson}";
            }
            if ($step === '') {
                return '{' . implode(',', $parts) . '}';
            }
            return "{\n" . implode(",\n", $parts) . "\n{$currentIndent}}";
        }
        if (\is_array($val)) {
            if ($val === []) {
                return '[]';
            }
            $parts = [];
            $nextIndent = $step !== '' ? $currentIndent . $step : '';
            foreach ($val as $item) {
                $vJson = self::serializeJson($item, $step, $nextIndent, $hasNonFinite);
                $parts[] = $step !== '' ? "{$nextIndent}{$vJson}" : $vJson;
            }
            if ($step === '') {
                return '[' . implode(',', $parts) . ']';
            }
            return "[\n" . implode(",\n", $parts) . "\n{$currentIndent}]";
        }
        return 'null';
    }
}
