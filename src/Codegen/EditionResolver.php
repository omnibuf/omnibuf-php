<?php

declare(strict_types=1);

namespace OmniBuf\Codegen;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\Edition;
use Google\Protobuf\Internal\EnumDescriptorProto;
use Google\Protobuf\Internal\EnumValueDescriptorProto;
use Google\Protobuf\Internal\FeatureSet\EnumType;
use Google\Protobuf\Internal\FeatureSet\FieldPresence;
use Google\Protobuf\Internal\FeatureSet\Utf8Validation;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\OneofDescriptorProto;

/**
 * Protobuf Editions (2023, 2024, 2026) feature inheritance resolver and validator.
 *
 * Shared by both the Tier 1 protoc plugin (`Generator`) and the Tier 2 reflection
 * adapter (`ProtobufCodec`).
 */
final class EditionResolver
{
    public const int MIN_EDITION = Edition::EDITION_2023;
    public const int MAX_EDITION = Edition::EDITION_2026;

    /** @var array<int, true> */
    private const array SUPPORTED_EDITIONS = [
        Edition::EDITION_2023 => true,
        Edition::EDITION_2024 => true,
        Edition::EDITION_2026 => true,
    ];

    /** @var array<string, true> */
    private const array OPTION_EXTENDEES = [
        '.google.protobuf.FileOptions' => true,
        '.google.protobuf.MessageOptions' => true,
        '.google.protobuf.FieldOptions' => true,
        '.google.protobuf.OneofOptions' => true,
        '.google.protobuf.EnumOptions' => true,
        '.google.protobuf.EnumValueOptions' => true,
        '.google.protobuf.ExtensionRangeOptions' => true,
        '.google.protobuf.ServiceOptions' => true,
        '.google.protobuf.MethodOptions' => true,
        '.google.protobuf.FeatureSet' => true,
    ];

    /**
     * Resolves file-level features for a `FileDescriptorProto`.
     *
     * When `$strict` is true, rejects unsupported editions and unsupported syntaxes.
     */
    public static function resolveFileFeatures(FileDescriptorProto $file, bool $strict = true): ResolvedFeatures
    {
        $syntax = (string) $file->getSyntax();
        $hasEdition = $file->hasEdition();
        $edition = (int) $file->getEdition();
        $fileName = (string) $file->getName();

        if ($syntax === 'proto3' && (!$hasEdition || $edition === Edition::EDITION_PROTO3)) {
            return ResolvedFeatures::forLegacy(false);
        }

        if (($syntax === '' || $syntax === 'proto2') && (!$hasEdition || $edition === Edition::EDITION_PROTO2)) {
            return ResolvedFeatures::forLegacy(true);
        }

        if ($syntax === 'editions' || $hasEdition) {
            if (!isset(self::SUPPORTED_EDITIONS[$edition])) {
                if ($strict) {
                    throw new \RuntimeException(
                        "omnibuf: unsupported protobuf edition {$edition} in '{$fileName}'; "
                        . 'supported editions are 2023 (1000), 2024 (1001), and 2026 (1002)',
                    );
                }
                return ResolvedFeatures::forEdition($edition)->merge($file->getOptions()?->getFeatures());
            }

            return ResolvedFeatures::forEdition($edition)->merge($file->getOptions()?->getFeatures());
        }

        if ($strict) {
            throw new \RuntimeException("omnibuf: unsupported protobuf syntax '{$syntax}' in '{$fileName}'");
        }

        return ResolvedFeatures::forLegacy(false);
    }

    /**
     * Indexes all enums (top-level and nested) across a set of `FileDescriptorProto`s
     * so cross-file closed-enum and enum-default checks resolve accurately.
     *
     * @param iterable<FileDescriptorProto> $files
     * @return array<string, array{isClosed: bool, valuesByName: array<string, int>}>
     */
    public static function indexEnums(iterable $files): array
    {
        $index = [];
        foreach ($files as $file) {
            $fileFeatures = self::resolveFileFeatures($file, false);
            $pkg = (string) $file->getPackage();
            $scope = $pkg !== '' ? ".{$pkg}" : '';
            foreach ($file->getEnumType() as $enum) {
                /** @var EnumDescriptorProto $enum */
                self::recordEnum($enum, $scope, $fileFeatures, $index);
            }
            foreach ($file->getMessageType() as $msg) {
                /** @var DescriptorProto $msg */
                self::indexMessageEnums($msg, $scope, $fileFeatures, $index);
            }
        }
        return $index;
    }

    /**
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $index
     */
    private static function indexMessageEnums(
        DescriptorProto $msg,
        string $scope,
        ResolvedFeatures $parentFeatures,
        array &$index,
    ): void {
        $msgFeatures = $parentFeatures->merge($msg->getOptions()?->getFeatures());
        $full = "{$scope}." . (string) $msg->getName();
        foreach ($msg->getEnumType() as $enum) {
            /** @var EnumDescriptorProto $enum */
            self::recordEnum($enum, $full, $msgFeatures, $index);
        }
        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            self::indexMessageEnums($nested, $full, $msgFeatures, $index);
        }
    }

    /**
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $index
     */
    private static function recordEnum(
        EnumDescriptorProto $enum,
        string $scope,
        ResolvedFeatures $parentFeatures,
        array &$index,
    ): void {
        $resolved = $parentFeatures->merge($enum->getOptions()?->getFeatures());
        $full = "{$scope}." . (string) $enum->getName();
        $valuesByName = [];
        foreach ($enum->getValue() as $v) {
            /** @var EnumValueDescriptorProto $v */
            $valuesByName[(string) $v->getName()] = (int) $v->getNumber();
        }
        $index[$full] = [
            'isClosed' => $resolved->enumType === EnumType::CLOSED,
            'valuesByName' => $valuesByName,
        ];
    }

    /**
     * Validates a `FileDescriptorProto` against OmniBuf's Editions 2023/2024/2026 rules
     * (and legacy `TYPE_GROUP` / `LABEL_REQUIRED` checks).
     *
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $enumIndex
     */
    public static function validateFile(FileDescriptorProto $file, array $enumIndex): ResolvedFeatures
    {
        $fileFeatures = self::resolveFileFeatures($file, true);
        $fileName = (string) $file->getName();
        $pkg = (string) $file->getPackage();
        $scope = $pkg !== '' ? ".{$pkg}" : '';

        if ($fileFeatures->isEditions) {
            foreach ($file->getExtension() as $ext) {
                /** @var FieldDescriptorProto $ext */
                self::validateExtension($ext, $fileName);
            }
            foreach ($file->getEnumType() as $enum) {
                /** @var EnumDescriptorProto $enum */
                self::validateEnum($enum, $scope, $fileFeatures);
            }
        }

        foreach ($file->getMessageType() as $msg) {
            /** @var DescriptorProto $msg */
            self::validateMessage($msg, $scope, $fileFeatures, $enumIndex, $fileName);
        }

        return $fileFeatures;
    }

    /**
     * Validates a single message (and its nested types, enums, extensions, and fields).
     *
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $enumIndex
     */
    public static function validateMessage(
        DescriptorProto $msg,
        string $scope,
        ResolvedFeatures $parentFeatures,
        array $enumIndex,
        string $fileName = '',
    ): ResolvedFeatures {
        $msgFeatures = $parentFeatures->merge($msg->getOptions()?->getFeatures());
        $full = "{$scope}." . (string) $msg->getName();
        $displayMsg = ltrim($full, '.');

        if ($msgFeatures->isEditions) {
            foreach ($msg->getExtension() as $ext) {
                /** @var FieldDescriptorProto $ext */
                self::validateExtension($ext, $displayMsg !== '' ? $displayMsg : $fileName);
            }
            foreach ($msg->getEnumType() as $enum) {
                /** @var EnumDescriptorProto $enum */
                self::validateEnum($enum, $full, $msgFeatures);
            }
        }

        /** @var array<string, DescriptorProto> $mapEntries */
        $mapEntries = [];
        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $nestedFull = "{$full}." . (string) $nested->getName();
            if ($nested->getOptions()?->getMapEntry()) {
                $mapEntries[$nestedFull] = $nested;
            }
            self::validateMessage($nested, $full, $msgFeatures, $enumIndex, $fileName);
        }

        /** @var list<OneofDescriptorProto> $oneofs */
        $oneofs = [];
        foreach ($msg->getOneofDecl() as $od) {
            /** @var OneofDescriptorProto $od */
            $oneofs[] = $od;
        }

        foreach ($msg->getField() as $f) {
            /** @var FieldDescriptorProto $f */
            $fieldFeatures = self::resolveFieldFeatures($f, $msgFeatures, $oneofs);
            $fieldPath = "{$displayMsg}." . (string) $f->getName();
            $type = (int) $f->getType();

            if ($type === Type::TYPE_GROUP && !$fieldFeatures->isEditions) {
                throw new \RuntimeException(
                    "omnibuf: legacy proto2 group field '{$fieldPath}' is not supported; "
                    . 'use Protobuf Editions with features.message_encoding = DELIMITED or a regular message',
                );
            }

            if ((int) $f->getLabel() === Label::LABEL_REQUIRED || $fieldFeatures->fieldPresence === FieldPresence::LEGACY_REQUIRED) {
                throw new \RuntimeException(
                    "omnibuf: field '{$fieldPath}' uses unsupported field_presence = LEGACY_REQUIRED",
                );
            }

            if (!$fieldFeatures->isEditions) {
                continue;
            }

            if ($type === Type::TYPE_STRING && $fieldFeatures->utf8Validation === Utf8Validation::NONE) {
                throw new \RuntimeException(
                    "omnibuf: string field '{$fieldPath}' uses unsupported utf8_validation = NONE; "
                    . 'OmniBuf requires strict UTF-8 validation',
                );
            }

            if ($type === Type::TYPE_ENUM) {
                $enumKey = self::normalizeTypeName((string) $f->getTypeName());
                if (isset($enumIndex[$enumKey]) && $enumIndex[$enumKey]['isClosed']) {
                    throw new \RuntimeException(
                        "omnibuf: field '{$fieldPath}' references closed enum '{$enumKey}' "
                        . '(features.enum_type = CLOSED is not supported in Editions)',
                    );
                }
            }

            if ($f->hasDefaultValue() && !self::isZeroDefault($f, $enumIndex)) {
                $rawDefault = (string) $f->getDefaultValue();
                throw new \RuntimeException(
                    "omnibuf: field '{$fieldPath}' has unsupported custom non-zero default value '{$rawDefault}' in Editions",
                );
            }

            // Also propagate field-level features onto synthetic map entry fields (e.g. map<string, ...> [features.utf8_validation = NONE])
            if ($type === Type::TYPE_MESSAGE && (int) $f->getLabel() === Label::LABEL_REPEATED) {
                $entryKey = self::normalizeTypeName((string) $f->getTypeName());
                if (isset($mapEntries[$entryKey])) {
                    $entryMsg = $mapEntries[$entryKey];
                    $entryFeatures = $fieldFeatures->merge($entryMsg->getOptions()?->getFeatures());
                    foreach ($entryMsg->getField() as $entryField) {
                        /** @var FieldDescriptorProto $entryField */
                        $efFeatures = $entryFeatures->merge($entryField->getOptions()?->getFeatures());
                        $efType = (int) $entryField->getType();
                        if ($efType === Type::TYPE_STRING && $efFeatures->utf8Validation === Utf8Validation::NONE) {
                            throw new \RuntimeException(
                                "omnibuf: map string field '{$fieldPath}' uses unsupported utf8_validation = NONE; "
                                . 'OmniBuf requires strict UTF-8 validation',
                            );
                        }
                        if ($efType === Type::TYPE_ENUM) {
                            $enumKey = self::normalizeTypeName((string) $entryField->getTypeName());
                            if (isset($enumIndex[$enumKey]) && $enumIndex[$enumKey]['isClosed']) {
                                throw new \RuntimeException(
                                    "omnibuf: map field '{$fieldPath}' references closed enum '{$enumKey}' "
                                    . '(features.enum_type = CLOSED is not supported in Editions)',
                                );
                            }
                        }
                    }
                }
            }
        }

        return $msgFeatures;
    }

    /**
     * Resolves effective features for a field, inheriting through any real containing oneof.
     *
     * @param list<OneofDescriptorProto> $oneofs
     */
    public static function resolveFieldFeatures(
        FieldDescriptorProto $field,
        ResolvedFeatures $msgFeatures,
        array $oneofs,
    ): ResolvedFeatures {
        $features = $msgFeatures;
        if ($field->hasOneofIndex() && !$field->getProto3Optional()) {
            $idx = (int) $field->getOneofIndex();
            if (isset($oneofs[$idx])) {
                $features = $features->merge($oneofs[$idx]->getOptions()?->getFeatures());
            }
        }
        return $features->merge($field->getOptions()?->getFeatures());
    }

    /**
     * Returns true if a singular scalar or enum field tracks explicit presence.
     */
    public static function fieldHasExplicitPresence(
        FieldDescriptorProto $field,
        ResolvedFeatures $fieldFeatures,
    ): bool {
        if ((int) $field->getLabel() === Label::LABEL_REPEATED) {
            return false;
        }
        if ($field->hasOneofIndex()) {
            return true;
        }
        if ($field->getProto3Optional()) {
            return true;
        }
        return $fieldFeatures->isEditions && $fieldFeatures->fieldPresence === FieldPresence::EXPLICIT;
    }

    public static function isMessageType(int $protoType, bool $isEditions): bool
    {
        return $protoType === Type::TYPE_MESSAGE || ($isEditions && $protoType === Type::TYPE_GROUP);
    }

    public static function normalizeTypeName(string $name): string
    {
        return $name !== '' && $name[0] !== '.' ? ".{$name}" : $name;
    }

    private static function validateEnum(
        EnumDescriptorProto $enum,
        string $scope,
        ResolvedFeatures $parentFeatures,
    ): void {
        $resolved = $parentFeatures->merge($enum->getOptions()?->getFeatures());
        if ($resolved->enumType === EnumType::CLOSED) {
            $full = ltrim("{$scope}." . (string) $enum->getName(), '.');
            throw new \RuntimeException(
                "omnibuf: closed enum '{$full}' (features.enum_type = CLOSED) is not supported in Editions",
            );
        }
    }

    private static function validateExtension(FieldDescriptorProto $ext, string $context): void
    {
        $extendee = self::normalizeTypeName((string) $ext->getExtendee());
        if (!isset(self::OPTION_EXTENDEES[$extendee])) {
            $name = (string) $ext->getName();
            throw new \RuntimeException(
                "omnibuf: non-option extension '{$name}' extending '{$extendee}' in '{$context}' is not supported in Editions",
            );
        }
    }

    /**
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $enumIndex
     */
    private static function isZeroDefault(FieldDescriptorProto $field, array $enumIndex): bool
    {
        $raw = (string) $field->getDefaultValue();
        $type = (int) $field->getType();

        return match ($type) {
            Type::TYPE_BOOL => $raw === 'false',
            Type::TYPE_STRING, Type::TYPE_BYTES => $raw === '',
            Type::TYPE_INT32, Type::TYPE_INT64, Type::TYPE_UINT32, Type::TYPE_UINT64,
            Type::TYPE_SINT32, Type::TYPE_SINT64, Type::TYPE_FIXED32, Type::TYPE_FIXED64,
            Type::TYPE_SFIXED32, Type::TYPE_SFIXED64 => $raw === '0' || $raw === '-0',
            Type::TYPE_FLOAT, Type::TYPE_DOUBLE => is_numeric($raw)
                && (float) $raw === 0.0
                && !str_starts_with(ltrim($raw), '-'),
            Type::TYPE_ENUM => self::isZeroEnumDefault($field, $raw, $enumIndex),
            default => false,
        };
    }

    /**
     * @param array<string, array{isClosed: bool, valuesByName: array<string, int>}> $enumIndex
     */
    private static function isZeroEnumDefault(FieldDescriptorProto $field, string $raw, array $enumIndex): bool
    {
        if ($raw === '0') {
            return true;
        }
        $enumKey = self::normalizeTypeName((string) $field->getTypeName());
        if (!isset($enumIndex[$enumKey])) {
            return false;
        }
        return ($enumIndex[$enumKey]['valuesByName'][$raw] ?? null) === 0;
    }
}
