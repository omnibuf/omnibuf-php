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

final class TestEditions2024 implements GeneratedMessage
{
    private static int $nativeSchemaId = -2;

    public const int CHOICE_NOT_SET = 0;
    public const int CHOICE_CHOICE_INT = 14;
    public const int CHOICE_CHOICE_TEXT = 15;
    public const int CHOICE_CHOICE_FLAG = 16;
    public const int CHOICE_CHOICE_CHILD = 17;

    public ?int $explicitInt = null;

    public ?bool $explicitBool = null;

    public ?string $explicitString = null;

    public ?string $explicitBytes = null;

    public ?float $explicitDouble = null;

    public ?int $status = null;

    public int $implicitCount = 0;

    public bool $implicitFlag = false;

    public ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $child = null;

    public ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $delimitedChild = null;

    /** @var list<int> */
    public array $packedVals = [];

    /** @var list<int> */
    public array $expandedVals = [];

    /** @var array<string, int> */
    public array $scores = [];

    public ?int $choiceInt = null;

    public ?string $choiceText = null;

    public ?bool $choiceFlag = null;

    public ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $choiceChild = null;

    public function __construct(
        ?int $explicitInt = null,
        ?bool $explicitBool = null,
        ?string $explicitString = null,
        ?string $explicitBytes = null,
        ?float $explicitDouble = null,
        ?int $status = null,
        ?int $implicitCount = null,
        ?bool $implicitFlag = null,
        ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $child = null,
        ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $delimitedChild = null,
        ?array $packedVals = null,
        ?array $expandedVals = null,
        ?array $scores = null,
        ?int $choiceInt = null,
        ?string $choiceText = null,
        ?bool $choiceFlag = null,
        ?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $choiceChild = null,
    ) {
        if ($explicitInt !== null) { $this->explicitInt = $explicitInt; }
        if ($explicitBool !== null) { $this->explicitBool = $explicitBool; }
        if ($explicitString !== null) { $this->explicitString = $explicitString; }
        if ($explicitBytes !== null) { $this->explicitBytes = $explicitBytes; }
        if ($explicitDouble !== null) { $this->explicitDouble = $explicitDouble; }
        if ($status !== null) { $this->status = $status; }
        if ($implicitCount !== null) { $this->implicitCount = $implicitCount; }
        if ($implicitFlag !== null) { $this->implicitFlag = $implicitFlag; }
        if ($child !== null) { $this->child = $child; }
        if ($delimitedChild !== null) { $this->delimitedChild = $delimitedChild; }
        if ($packedVals !== null) { $this->packedVals = $packedVals; }
        if ($expandedVals !== null) { $this->expandedVals = $expandedVals; }
        if ($scores !== null) { $this->scores = $scores; }
        if ($choiceInt !== null) { $this->setChoiceInt($choiceInt); }
        if ($choiceText !== null) { $this->setChoiceText($choiceText); }
        if ($choiceFlag !== null) { $this->setChoiceFlag($choiceFlag); }
        if ($choiceChild !== null) { $this->setChoiceChild($choiceChild); }
    }

    public function getChoiceCase(): int
    {
        if ($this->choiceInt !== null) { return 14; }
        if ($this->choiceText !== null) { return 15; }
        if ($this->choiceFlag !== null) { return 16; }
        if ($this->choiceChild !== null) { return 17; }
        return 0;
    }

    public function clearChoice(): self
    {
        $this->choiceInt = null;
        $this->choiceText = null;
        $this->choiceFlag = null;
        $this->choiceChild = null;
        return $this;
    }

    public function setChoiceInt(?int $value): self
    {
        $this->choiceText = null;
        $this->choiceFlag = null;
        $this->choiceChild = null;
        $this->choiceInt = $value;
        return $this;
    }

    public function setChoiceText(?string $value): self
    {
        $this->choiceInt = null;
        $this->choiceFlag = null;
        $this->choiceChild = null;
        $this->choiceText = $value;
        return $this;
    }

    public function setChoiceFlag(?bool $value): self
    {
        $this->choiceInt = null;
        $this->choiceText = null;
        $this->choiceChild = null;
        $this->choiceFlag = $value;
        return $this;
    }

    public function setChoiceChild(?\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child $value): self
    {
        $this->choiceInt = null;
        $this->choiceText = null;
        $this->choiceFlag = null;
        $this->choiceChild = $value;
        return $this;
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
        if ($this->explicitInt !== null) {
            $enc->buf .= "\x23";
            $n = ($this->explicitInt << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->explicitBool !== null) {
            $enc->buf .= $this->explicitBool ? "\x47" : "\x45";
        }
        if ($this->explicitString !== null) {
            $enc->buf .= "\x75";
            $enc->writeVarint(\strlen($this->explicitString));
            $enc->buf .= $this->explicitString;
        }
        if ($this->explicitBytes !== null) {
            $enc->buf .= "\x97";
            $enc->writeVarint(\strlen($this->explicitBytes));
            $enc->buf .= $this->explicitBytes;
        }
        if ($this->explicitDouble !== null) {
            $enc->buf .= "\xb3" . pack('e', $this->explicitDouble);
        }
        if ($this->status !== null) {
            $enc->buf .= "\xc3";
            $n = ($this->status << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->implicitCount !== 0) {
            $enc->buf .= "\xe3";
            $n = ($this->implicitCount << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->implicitFlag) {
            $enc->buf .= "\x0e\x02";
        }
        if ($this->child !== null) {
            $enc->buf .= "\x72\x02";
            $enc->beginContainer();
            $this->child->marshalTo($enc, $deterministic);
            $enc->endContainer();
        }
        if ($this->delimitedChild !== null) {
            $enc->buf .= "\xb2\x02";
            $enc->beginContainer();
            $this->delimitedChild->marshalTo($enc, $deterministic);
            $enc->endContainer();
        }
        if ($this->packedVals !== []) {
            $enc->buf .= "\xf6\x02";
            $enc->beginContainer();
            $enc->buf .= "\x01";
            $enc->writeVarint(\count($this->packedVals));
            foreach ($this->packedVals as $item) {
                $enc->writeSintRaw(($item << 32) >> 32);
            }
            $enc->endContainer();
        }
        if ($this->expandedVals !== []) {
            $enc->buf .= "\x36\x03";
            $enc->beginContainer();
            $enc->buf .= "\x01";
            $enc->writeVarint(\count($this->expandedVals));
            foreach ($this->expandedVals as $item) {
                $enc->writeSintRaw(($item << 32) >> 32);
            }
            $enc->endContainer();
        }
        if ($this->scores !== []) {
            $entries = $this->scores;
            if ($deterministic) {
                uksort($entries, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
            }
            $enc->buf .= "\x7a\x03";
            $enc->beginContainer();
            $enc->buf .= "\x0a\x01";
            $enc->writeVarint(\count($entries));
            foreach ($entries as $k => $v) {
                $enc->writeVarint(\strlen($k)); $enc->buf .= $k;
                $enc->writeSintRaw(($v << 32) >> 32);
            }
            $enc->endContainer();
        }
        if ($this->getChoiceCase() === 14 && $this->choiceInt !== null) {
            $enc->buf .= "\x86\x03";
            $n = ($this->choiceInt << 32) >> 32;
            $enc->writeVarint(($n << 1) ^ ($n >> 63));
        }
        if ($this->getChoiceCase() === 15 && $this->choiceText !== null) {
            $enc->buf .= "\xea\x03";
            $enc->writeVarint(\strlen($this->choiceText));
            $enc->buf .= $this->choiceText;
        }
        if ($this->getChoiceCase() === 16 && $this->choiceFlag !== null) {
            $enc->buf .= $this->choiceFlag ? "\x0e\x04" : "\x0a\x04";
        }
        if ($this->getChoiceCase() === 17 && $this->choiceChild !== null) {
            $enc->buf .= "\x72\x04";
            $enc->beginContainer();
            $this->choiceChild->marshalTo($enc, $deterministic);
            $enc->endContainer();
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
        self::$nativeSchemaId = -1;
        return null;
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
            $this->explicitInt = null;
            $this->explicitBool = null;
            $this->explicitString = null;
            $this->explicitBytes = null;
            $this->explicitDouble = null;
            $this->status = null;
            $this->implicitCount = 0;
            $this->implicitFlag = false;
            $this->child = null;
            $this->delimitedChild = null;
            $this->packedVals = [];
            $this->expandedVals = [];
            $this->scores = [];
            $this->choiceInt = null;
            $this->choiceText = null;
            $this->choiceFlag = null;
            $this->choiceChild = null;
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
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->explicitInt = $dec->readSint32();
                    break;
                case 2:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->explicitBool = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->explicitBool = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 3:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->explicitString = $dec->readString();
                    break;
                case 4:
                    if ($wireType !== 11) { $dec->skipUnknown($wireType); break; }
                    $this->explicitBytes = $dec->readBytes();
                    break;
                case 5:
                    if ($wireType !== 9) { $dec->skipUnknown($wireType); break; }
                    $this->explicitDouble = $dec->readFloat64();
                    break;
                case 6:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->status = $dec->readSint32();
                    break;
                case 7:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->implicitCount = $dec->readSint32();
                    break;
                case 8:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->implicitFlag = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->implicitFlag = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 9:
                    if ($wireType !== 12) { $dec->skipUnknown($wireType); break; }
                    $subDec = $dec->readSubMessageDecoder();
                    if ($this->child === null) {
                        $this->child = new \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child();
                        $this->child->decodeFrom($subDec, false);
                    } else {
                        $this->child->decodeFrom($subDec, true);
                    }
                    break;
                case 10:
                    if ($wireType !== 12) { $dec->skipUnknown($wireType); break; }
                    $subDec = $dec->readSubMessageDecoder();
                    if ($this->delimitedChild === null) {
                        $this->delimitedChild = new \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child();
                        $this->delimitedChild->decodeFrom($subDec, false);
                    } else {
                        $this->delimitedChild->decodeFrom($subDec, true);
                    }
                    break;
                case 11:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(1);
                    for ($i = 0; $i < $count; $i++) {
                        $this->packedVals[] = $arrDec->readSint32();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 12:
                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }
                    [$arrDec, $_et, $count] = $dec->readArrayHeader(1);
                    for ($i = 0; $i < $count; $i++) {
                        $this->expandedVals[] = $arrDec->readSint32();
                    }
                    $arrDec->assertAtEnd('WireArray');
                    break;
                case 13:
                    if ($wireType !== WireType::MAP) { $dec->skipUnknown($wireType); break; }
                    [$mapDec, $_kt, $_vt, $count] = $dec->readMapHeader(10, 1);
                    for ($i = 0; $i < $count; $i++) {
                        $k = $mapDec->readString();
                        $this->scores[$k] = $mapDec->readSint32();
                    }
                    $mapDec->assertAtEnd('WireMap');
                    break;
                case 14:
                    if ($wireType !== 1) { $dec->skipUnknown($wireType); break; }
                    $this->choiceText = null;
                    $this->choiceFlag = null;
                    $this->choiceChild = null;
                    $this->choiceInt = $dec->readSint32();
                    break;
                case 15:
                    if ($wireType !== 10) { $dec->skipUnknown($wireType); break; }
                    $this->choiceInt = null;
                    $this->choiceFlag = null;
                    $this->choiceChild = null;
                    $this->choiceText = $dec->readString();
                    break;
                case 16:
                    if ($wireType === WireType::BOOL_FALSE) {
                        $this->choiceInt = null;
                        $this->choiceText = null;
                        $this->choiceChild = null;
                        $this->choiceFlag = false;
                    } elseif ($wireType === WireType::BOOL_TRUE) {
                        $this->choiceInt = null;
                        $this->choiceText = null;
                        $this->choiceChild = null;
                        $this->choiceFlag = true;
                    } else {
                        $dec->skipUnknown($wireType);
                    }
                    break;
                case 17:
                    if ($wireType !== 12) { $dec->skipUnknown($wireType); break; }
                    $this->choiceInt = null;
                    $this->choiceText = null;
                    $this->choiceFlag = null;
                    $subDec = $dec->readSubMessageDecoder();
                    if ($this->choiceChild === null) {
                        $this->choiceChild = new \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child();
                        $this->choiceChild->decodeFrom($subDec, false);
                    } else {
                        $this->choiceChild->decodeFrom($subDec, true);
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
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::class])) {
            $children[9] = \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::schemaMeta($visited);
        }
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::class])) {
            $children[10] = \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::schemaMeta($visited);
        }
        if (!isset($visited[\OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::class])) {
            $children[17] = \OmniBuf\Generated\Omnibuf\Conformance\Editions2024Child::schemaMeta($visited);
        }
        return new SchemaMeta(
            'omnibuf.conformance.TestEditions2024',
            [
                1 => 'explicit_int',
                2 => 'explicit_bool',
                3 => 'explicit_string',
                4 => 'explicit_bytes',
                5 => 'explicit_double',
                6 => 'status',
                7 => 'implicit_count',
                8 => 'implicit_flag',
                9 => 'child',
                10 => 'delimited_child',
                11 => 'packed_vals',
                12 => 'expanded_vals',
                13 => 'scores',
                14 => 'choice_int',
                15 => 'choice_text',
                16 => 'choice_flag',
                17 => 'choice_child',
            ],
            $children,
        );
    }
}
