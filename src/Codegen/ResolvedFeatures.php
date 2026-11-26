<?php

declare(strict_types=1);

namespace OmniBuf\Codegen;

use Google\Protobuf\Internal\Edition;
use Google\Protobuf\Internal\FeatureSet;
use Google\Protobuf\Internal\FeatureSet\EnumType;
use Google\Protobuf\Internal\FeatureSet\FieldPresence;
use Google\Protobuf\Internal\FeatureSet\JsonFormat;
use Google\Protobuf\Internal\FeatureSet\MessageEncoding;
use Google\Protobuf\Internal\FeatureSet\RepeatedFieldEncoding;
use Google\Protobuf\Internal\FeatureSet\Utf8Validation;

/**
 * Immutable resolved Protobuf Editions feature set at a lexical scope
 * (file -> message -> nested message -> oneof -> field, or file -> message -> enum).
 */
final readonly class ResolvedFeatures
{
    public function __construct(
        public bool $isEditions,
        public int $edition,
        public int $fieldPresence,
        public int $enumType,
        public int $repeatedFieldEncoding,
        public int $utf8Validation,
        public int $messageEncoding,
        public int $jsonFormat,
    ) {}

    public static function forLegacy(bool $isProto2): self
    {
        return new self(
            isEditions: false,
            edition: $isProto2 ? Edition::EDITION_PROTO2 : Edition::EDITION_PROTO3,
            fieldPresence: FieldPresence::IMPLICIT,
            enumType: $isProto2 ? EnumType::CLOSED : EnumType::OPEN,
            repeatedFieldEncoding: RepeatedFieldEncoding::PACKED,
            utf8Validation: Utf8Validation::VERIFY,
            messageEncoding: MessageEncoding::LENGTH_PREFIXED,
            jsonFormat: JsonFormat::ALLOW,
        );
    }

    /**
     * Returns default runtime features for a supported Protobuf Edition (2023, 2024, or 2026).
     *
     * Source-only features introduced in Editions 2024/2026 (enforce_naming_style,
     * default_symbol_visibility, enforce_proto_limits) are validated by protoc and
     * do not alter OmniBuf runtime semantics.
     */
    public static function forEdition(int $edition): self
    {
        return new self(
            isEditions: true,
            edition: $edition,
            fieldPresence: FieldPresence::EXPLICIT,
            enumType: EnumType::OPEN,
            repeatedFieldEncoding: RepeatedFieldEncoding::PACKED,
            utf8Validation: Utf8Validation::VERIFY,
            messageEncoding: MessageEncoding::LENGTH_PREFIXED,
            jsonFormat: JsonFormat::ALLOW,
        );
    }

    public function merge(?FeatureSet $override): self
    {
        if ($override === null) {
            return $this;
        }

        $fp = $override->hasFieldPresence() && (int) $override->getFieldPresence() !== FieldPresence::FIELD_PRESENCE_UNKNOWN
            ? (int) $override->getFieldPresence()
            : $this->fieldPresence;
        $et = $override->hasEnumType() && (int) $override->getEnumType() !== EnumType::ENUM_TYPE_UNKNOWN
            ? (int) $override->getEnumType()
            : $this->enumType;
        $rfe = $override->hasRepeatedFieldEncoding() && (int) $override->getRepeatedFieldEncoding() !== RepeatedFieldEncoding::REPEATED_FIELD_ENCODING_UNKNOWN
            ? (int) $override->getRepeatedFieldEncoding()
            : $this->repeatedFieldEncoding;
        $utf8 = $override->hasUtf8Validation() && (int) $override->getUtf8Validation() !== Utf8Validation::UTF8_VALIDATION_UNKNOWN
            ? (int) $override->getUtf8Validation()
            : $this->utf8Validation;
        $me = $override->hasMessageEncoding() && (int) $override->getMessageEncoding() !== MessageEncoding::MESSAGE_ENCODING_UNKNOWN
            ? (int) $override->getMessageEncoding()
            : $this->messageEncoding;
        $jf = $override->hasJsonFormat() && (int) $override->getJsonFormat() !== JsonFormat::JSON_FORMAT_UNKNOWN
            ? (int) $override->getJsonFormat()
            : $this->jsonFormat;

        return new self(
            isEditions: $this->isEditions,
            edition: $this->edition,
            fieldPresence: $fp,
            enumType: $et,
            repeatedFieldEncoding: $rfe,
            utf8Validation: $utf8,
            messageEncoding: $me,
            jsonFormat: $jf,
        );
    }
}
