<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

use Google\Protobuf\Internal\Descriptor;
use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FeatureSet\FieldPresence;
use Google\Protobuf\Internal\FieldDescriptor;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\FileDescriptorSet;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\OneofDescriptorProto;
use OmniBuf\Codegen\EditionResolver;
use OmniBuf\Codegen\ResolvedFeatures;
use OmniBuf\UInt64;

/**
 * Extracts, validates, and caches Protobuf Editions metadata for `protoc --php_out` classes.
 *
 * Pure-PHP `google/protobuf` discards `FileDescriptorProto` features after building runtime
 * `Descriptor` instances, so this helper recovers the original `FileDescriptorSet` embedded in
 * `GPBMetadata\...::initOnce()` via token inspection (without `eval`) and validates it with
 * `EditionResolver`. Falls back gracefully to runtime `Descriptor` inspection for manually
 * constructed descriptors.
 *
 * @internal
 */
final class ProtobufEditionMetadata
{
    /** @var array<class-string<Message>, array{isEditions: bool, explicitPresence: array<int, bool>}> */
    private static array $cache = [];

    /** @var array<string, list<FileDescriptorProto>> */
    private static array $metadataFileCache = [];

    /**
     * Validates a `Google\Protobuf\Internal\Message` class and returns resolved Editions flags.
     *
     * @param class-string<Message> $class
     * @return array{isEditions: bool, explicitPresence: array<int, bool>}
     */
    public static function forClass(string $class, Descriptor $desc): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $isEditions = false;
        $explicitPresence = [];
        $validatedByFileProto = false;

        $metaFiles = self::extractFileDescriptorsForClass($class);
        if ($metaFiles !== []) {
            $targetFullName = ltrim((string) $desc->getFullName(), '.');
            $enumIndex = EditionResolver::indexEnums($metaFiles);

            foreach ($metaFiles as $file) {
                $pkg = (string) $file->getPackage();
                $found = self::findDescriptorProto($file, $pkg, $targetFullName);
                if ($found === null) {
                    continue;
                }
                $fileFeatures = EditionResolver::validateFile($file, $enumIndex);
                $isEditions = $fileFeatures->isEditions;
                [$msgProto, $msgFeatures] = $found;
                $msgFeatures = self::resolvePathFeatures($file, $fileFeatures, $msgProto);

                /** @var list<OneofDescriptorProto> $oneofs */
                $oneofs = [];
                foreach ($msgProto->getOneofDecl() as $od) {
                    /** @var OneofDescriptorProto $od */
                    $oneofs[] = $od;
                }
                foreach ($msgProto->getField() as $fp) {
                    /** @var FieldDescriptorProto $fp */
                    $ff = EditionResolver::resolveFieldFeatures($fp, $msgFeatures, $oneofs);
                    $explicitPresence[(int) $fp->getNumber()] = EditionResolver::fieldHasExplicitPresence($fp, $ff);
                }
                $validatedByFileProto = true;
                break;
            }
        }

        // Direct runtime Descriptor checks (also covers manually built descriptors without GPBMetadata)
        $msgOpts = $desc->getOptions();
        if ($msgOpts !== null && $msgOpts->getFeatures() !== null) {
            $mFeat = $msgOpts->getFeatures();
            if ($mFeat->hasFieldPresence() && (int) $mFeat->getFieldPresence() === FieldPresence::LEGACY_REQUIRED) {
                throw new \RuntimeException(
                    "omnibuf: message '{$desc->getFullName()}' uses unsupported field_presence = LEGACY_REQUIRED",
                );
            }
        }

        $fresh = null;
        /** @var array<int, FieldDescriptor> $fields */
        $fields = $desc->getField();
        foreach ($fields as $num => $fd) {
            $fieldPath = $desc->getFullName() . '.' . (string) $fd->getName();
            $gpbType = (int) $fd->getType();

            if ($fd->isRequired()) {
                throw new \RuntimeException(
                    "omnibuf: field '{$fieldPath}' uses unsupported field_presence = LEGACY_REQUIRED",
                );
            }

            if ($gpbType === GPBType::GROUP && !$isEditions) {
                throw new \RuntimeException(
                    "omnibuf: legacy proto2 group field '{$fieldPath}' is not supported",
                );
            }

            if (!$validatedByFileProto) {
                $getter = (string) $fd->getGetter();
                $hasser = 'has' . substr($getter, 3);
                $hasExplicit = !$fd->isRepeated()
                    && !$fd->isMap()
                    && ($fd->hasPresence() || $fd->getContainingOneof() !== null || method_exists($class, $hasser));
                $explicitPresence[(int) $num] = $hasExplicit;

                if (
                    $hasExplicit
                    && $gpbType !== GPBType::MESSAGE
                    && $gpbType !== GPBType::GROUP
                    && method_exists($class, $hasser)
                ) {
                    $fresh ??= new $class();
                    if (!$fresh->{$hasser}() && !self::isZeroDefaultValue($gpbType, $fresh->{$getter}())) {
                        throw new \RuntimeException(
                            "omnibuf: field '{$fieldPath}' has unsupported custom non-zero default value",
                        );
                    }
                }
            }
        }

        $entry = [
            'isEditions' => $isEditions,
            'explicitPresence' => $explicitPresence,
        ];
        self::$cache[$class] = $entry;
        return $entry;
    }

    /**
     * @param class-string<Message> $class
     * @return list<FileDescriptorProto>
     */
    private static function extractFileDescriptorsForClass(string $class): array
    {
        $ctor = new \ReflectionMethod($class, '__construct');
        $fileName = $ctor->getFileName();
        $startLine = $ctor->getStartLine();
        $endLine = $ctor->getEndLine();
        if ($fileName === false || $startLine === false || $endLine === false || !is_file($fileName)) {
            return [];
        }
        $lines = @file($fileName);
        if ($lines === false) {
            return [];
        }
        $body = implode('', \array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        if (!preg_match('/\\\\?([A-Za-z0-9_\\\\]+)::initOnce\s*\(/', $body, $m)) {
            return [];
        }
        $metaClass = ltrim($m[1], '\\');
        if (isset(self::$metadataFileCache[$metaClass])) {
            return self::$metadataFileCache[$metaClass];
        }

        $visited = [];
        $files = [];
        self::collectFromMetadataClass($metaClass, $visited, $files);
        self::$metadataFileCache[$metaClass] = $files;
        return $files;
    }

    /**
     * @param array<string, true> $visited
     * @param list<FileDescriptorProto> $outFiles
     */
    private static function collectFromMetadataClass(string $metaClass, array &$visited, array &$outFiles): void
    {
        if (isset($visited[$metaClass]) || !class_exists($metaClass)) {
            return;
        }
        $visited[$metaClass] = true;

        try {
            $initMethod = new \ReflectionMethod($metaClass, 'initOnce');
        } catch (\ReflectionException) {
            return;
        }
        $fileName = $initMethod->getFileName();
        if ($fileName === false || !is_file($fileName)) {
            return;
        }
        $code = @file_get_contents($fileName);
        if ($code === false) {
            return;
        }

        // Follow imported GPBMetadata::initOnce() calls first so cross-file enums are indexed
        if (preg_match_all('/\\\\?([A-Za-z0-9_\\\\]+)::initOnce\s*\(/', $code, $matches)) {
            foreach ($matches[1] as $dep) {
                $depClass = ltrim($dep, '\\');
                if ($depClass !== $metaClass) {
                    self::collectFromMetadataClass($depClass, $visited, $outFiles);
                }
            }
        }

        $rawDescriptorSet = self::extractGeneratedFilePayload($code);
        if ($rawDescriptorSet === null) {
            return;
        }
        $fds = new FileDescriptorSet();
        $fds->mergeFromString($rawDescriptorSet);
        foreach ($fds->getFile() as $fdp) {
            /** @var FileDescriptorProto $fdp */
            $outFiles[] = $fdp;
        }
    }

    /**
     * Extracts the first string-literal argument passed to `internalAddGeneratedFile(...)`
     * using PHP's built-in lexer (`token_get_all`), without `eval`.
     */
    private static function extractGeneratedFilePayload(string $phpCode): ?string
    {
        $tokens = token_get_all($phpCode);
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== 'internalAddGeneratedFile') {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }
            $j++;
            $payload = '';
            $foundString = false;
            while ($j < $count) {
                $cur = $tokens[$j];
                if ($cur === ',' || $cur === ')') {
                    break;
                }
                if (\is_array($cur) && $cur[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $payload .= self::decodePhpStringLiteral($cur[1]);
                    $foundString = true;
                }
                $j++;
            }
            if ($foundString) {
                return $payload;
            }
        }
        return null;
    }

    /**
     * Decodes a PHP single- or double-quoted `T_CONSTANT_ENCAPSED_STRING` literal.
     */
    public static function decodePhpStringLiteral(string $literal): string
    {
        $len = \strlen($literal);
        if ($len < 2) {
            return $literal;
        }
        $quote = $literal[0];
        $inner = substr($literal, 1, $len - 2);
        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        $out = '';
        $n = \strlen($inner);
        for ($i = 0; $i < $n; $i++) {
            $ch = $inner[$i];
            if ($ch !== '\\' || $i + 1 >= $n) {
                $out .= $ch;
                continue;
            }
            $next = $inner[++$i];
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'v':
                    $out .= "\v";
                    break;
                case 'e':
                    $out .= "\e";
                    break;
                case 'f':
                    $out .= "\f";
                    break;
                case '\\':
                    $out .= '\\';
                    break;
                case '$':
                    $out .= '$';
                    break;
                case '"':
                    $out .= '"';
                    break;
                case 'x':
                    $hex = '';
                    for ($k = 0; $k < 2 && $i + 1 < $n && ctype_xdigit($inner[$i + 1]); $k++) {
                        $hex .= $inner[++$i];
                    }
                    $out .= $hex !== '' ? \chr(((int) hexdec($hex)) & 0xFF) : '\\x';
                    break;
                default:
                    if ($next >= '0' && $next <= '7') {
                        $oct = $next;
                        for ($k = 0; $k < 2 && $i + 1 < $n && $inner[$i + 1] >= '0' && $inner[$i + 1] <= '7'; $k++) {
                            $oct .= $inner[++$i];
                        }
                        $out .= \chr((int) octdec($oct) & 0xFF);
                    } else {
                        $out .= '\\' . $next;
                    }
                    break;
            }
        }
        return $out;
    }

    /**
     * @return array{0: DescriptorProto, 1: ResolvedFeatures}|null
     */
    private static function findDescriptorProto(FileDescriptorProto $file, string $pkg, string $targetFullName): ?array
    {
        $dummy = ResolvedFeatures::forLegacy(false);
        foreach ($file->getMessageType() as $msg) {
            /** @var DescriptorProto $msg */
            $name = $pkg !== '' ? "{$pkg}." . (string) $msg->getName() : (string) $msg->getName();
            $found = self::findNestedDescriptorProto($msg, $name, $targetFullName, $dummy);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @return array{0: DescriptorProto, 1: ResolvedFeatures}|null
     */
    private static function findNestedDescriptorProto(
        DescriptorProto $msg,
        string $fullName,
        string $targetFullName,
        ResolvedFeatures $dummy,
    ): ?array {
        if ($fullName === $targetFullName) {
            return [$msg, $dummy];
        }
        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $childName = "{$fullName}." . (string) $nested->getName();
            $found = self::findNestedDescriptorProto($nested, $childName, $targetFullName, $dummy);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private static function resolvePathFeatures(
        FileDescriptorProto $file,
        ResolvedFeatures $fileFeatures,
        DescriptorProto $target,
    ): ResolvedFeatures {
        foreach ($file->getMessageType() as $msg) {
            /** @var DescriptorProto $msg */
            $res = self::resolveMessagePathFeatures($msg, $fileFeatures, $target);
            if ($res !== null) {
                return $res;
            }
        }
        return $fileFeatures;
    }

    private static function resolveMessagePathFeatures(
        DescriptorProto $msg,
        ResolvedFeatures $parentFeatures,
        DescriptorProto $target,
    ): ?ResolvedFeatures {
        $msgFeatures = $parentFeatures->merge($msg->getOptions()?->getFeatures());
        if ($msg === $target) {
            return $msgFeatures;
        }
        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $res = self::resolveMessagePathFeatures($nested, $msgFeatures, $target);
            if ($res !== null) {
                return $res;
            }
        }
        return null;
    }

    private static function isZeroDefaultValue(int $gpbType, mixed $val): bool
    {
        return match ($gpbType) {
            GPBType::UINT32, GPBType::INT32, GPBType::INT64, GPBType::SINT32, GPBType::SINT64,
            GPBType::FIXED32, GPBType::SFIXED32, GPBType::SFIXED64, GPBType::ENUM => (int) $val === 0,
            GPBType::UINT64, GPBType::FIXED64 => (\is_int($val) ? $val : UInt64::fromDecimalString((string) $val)) === 0,
            GPBType::BOOL => $val === false,
            GPBType::FLOAT => pack('g', (float) $val) === "\x00\x00\x00\x00",
            GPBType::DOUBLE => (float) $val === 0.0 && pack('e', (float) $val) === "\x00\x00\x00\x00\x00\x00\x00\x00",
            GPBType::STRING, GPBType::BYTES => $val === '',
            default => true,
        };
    }
}
