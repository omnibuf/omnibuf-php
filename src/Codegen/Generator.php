<?php

declare(strict_types=1);

namespace OmniBuf\Codegen;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\OneofDescriptorProto;
use OmniBuf\Wire;
use OmniBuf\WireType;

/**
 * Protoc plugin code generator (`protoc-gen-omnibuf-php`).
 *
 * Generates typed PHP 8.3+ message classes with inlined single-pass encoders/decoders
 * and lazy zero-decode `Opaque<Message>` views supporting in-place wire splicing.
 * Supports proto3 and Protobuf Editions 2023, 2024, and 2026.
 */
final class Generator
{
    /** @var array<string, array{phpClass: string, fullName: string, isMapEntry: bool, keyField: ?FieldDescriptorProto, valField: ?FieldDescriptorProto, descriptor: DescriptorProto}> */
    private array $messages = [];

    private string $namespacePrefix = '';

    public function run(string $rawRequest): string
    {
        $this->messages = [];
        $this->namespacePrefix = '';

        try {
            [$filesToGenerate, $parameter, $protoFiles] = $this->parseCodeGeneratorRequest($rawRequest);
            $this->parseParameter($parameter);

            foreach ($protoFiles as $file) {
                $this->indexFile($file);
            }
            $enumIndex = EditionResolver::indexEnums($protoFiles);

            $generateSet = array_flip($filesToGenerate);
            $outFiles = [];

            foreach ($protoFiles as $file) {
                $fileName = (string) $file->getName();
                if (!isset($generateSet[$fileName])) {
                    continue;
                }
                $fileFeatures = EditionResolver::validateFile($file, $enumIndex);
                $ns = $this->phpNamespaceForFile($file);
                $pkg = (string) $file->getPackage();
                foreach ($file->getMessageType() as $msg) {
                    /** @var DescriptorProto $msg */
                    $this->generateMessageRecursive($msg, $pkg, $ns, $fileFeatures, $outFiles);
                }
            }

            return $this->encodeCodeGeneratorResponse($outFiles, null);
        } catch (\Throwable $e) {
            return $this->encodeCodeGeneratorResponse([], $e->getMessage());
        }
    }

    private function parseParameter(string $parameter): void
    {
        if ($parameter === '') {
            return;
        }
        foreach (explode(',', $parameter) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'namespace_prefix=')) {
                $this->namespacePrefix = trim(substr($part, \strlen('namespace_prefix=')), '\\');
            }
        }
    }

    private function phpNamespaceForFile(FileDescriptorProto $file): string
    {
        $opts = $file->getOptions();
        $ns = '';
        if ($opts !== null && $opts->getPhpNamespace() !== '') {
            $ns = (string) $opts->getPhpNamespace();
        } else {
            $pkg = (string) $file->getPackage();
            if ($pkg !== '') {
                $parts = array_map(
                    static fn(string $p): string => implode('', array_map('ucfirst', explode('_', $p))),
                    explode('.', $pkg),
                );
                $ns = implode('\\', $parts);
            }
        }
        if ($this->namespacePrefix !== '') {
            return $ns !== '' ? $this->namespacePrefix . '\\' . $ns : $this->namespacePrefix;
        }
        return $ns;
    }

    private function indexFile(FileDescriptorProto $file): void
    {
        $pkg = (string) $file->getPackage();
        $ns = $this->phpNamespaceForFile($file);
        foreach ($file->getMessageType() as $msg) {
            /** @var DescriptorProto $msg */
            $this->indexMessage($msg, $pkg !== '' ? ".{$pkg}" : '', $ns);
        }
    }

    private function indexMessage(DescriptorProto $msg, string $scope, string $ns): void
    {
        $name = (string) $msg->getName();
        $full = "{$scope}.{$name}";
        $phpClass = ($ns !== '' ? "\\{$ns}\\" : '\\') . $name;
        $opts = $msg->getOptions();
        $isMapEntry = $opts !== null && (bool) $opts->getMapEntry();
        $keyField = null;
        $valField = null;
        if ($isMapEntry) {
            foreach ($msg->getField() as $f) {
                /** @var FieldDescriptorProto $f */
                if ((int) $f->getNumber() === 1) {
                    $keyField = $f;
                } elseif ((int) $f->getNumber() === 2) {
                    $valField = $f;
                }
            }
        }
        $this->messages[$full] = [
            'phpClass' => $phpClass,
            'fullName' => ltrim($full, '.'),
            'isMapEntry' => $isMapEntry,
            'keyField' => $keyField,
            'valField' => $valField,
            'descriptor' => $msg,
        ];
        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $this->indexMessage($nested, $full, $ns !== '' ? "{$ns}\\{$name}" : $name);
        }
    }

    /**
     * @param list<array{name: string, content: string}> $outFiles
     */
    private function generateMessageRecursive(
        DescriptorProto $msg,
        string $pkg,
        string $ns,
        ResolvedFeatures $parentFeatures,
        array &$outFiles,
    ): void {
        $opts = $msg->getOptions();
        if ($opts !== null && (bool) $opts->getMapEntry()) {
            return;
        }
        $msgFeatures = $parentFeatures->merge($opts?->getFeatures());
        $name = (string) $msg->getName();
        $fullName = $pkg !== '' ? "{$pkg}.{$name}" : $name;
        $dir = str_replace('\\', '/', $ns);
        $prefix = $dir !== '' ? "{$dir}/" : '';

        $outFiles[] = [
            'name' => "{$prefix}{$name}.php",
            'content' => $this->renderMessageClass($msg, $fullName, $ns, $msgFeatures),
        ];
        $outFiles[] = [
            'name' => "{$prefix}Opaque{$name}.php",
            'content' => $this->renderOpaqueClass($msg, $ns, $msgFeatures),
        ];

        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $this->generateMessageRecursive($nested, $fullName, $ns !== '' ? "{$ns}\\{$name}" : $name, $msgFeatures, $outFiles);
        }
    }

    /**
     * Analyzes fields and real oneof groups for a message descriptor.
     *
     * @return array{
     *     fields: list<FieldDescriptorProto>,
     *     fieldInfo: array<int, array{
     *         field: FieldDescriptorProto,
     *         features: ResolvedFeatures,
     *         explicitPresence: bool,
     *         realOneofIndex: ?int,
     *         isMessage: bool,
     *         isMap: bool,
     *         isRepeated: bool,
     *     }>,
     *     oneofs: array<int, array{
     *         index: int,
     *         name: string,
     *         pascal: string,
     *         upper: string,
     *         fields: list<FieldDescriptorProto>,
     *         wireTypes: array<int, int>,
     *     }>,
     * }
     */
    private function analyzeMessage(DescriptorProto $msg, ResolvedFeatures $msgFeatures): array
    {
        /** @var list<OneofDescriptorProto> $oneofDecls */
        $oneofDecls = [];
        foreach ($msg->getOneofDecl() as $od) {
            /** @var OneofDescriptorProto $od */
            $oneofDecls[] = $od;
        }

        $fields = $this->sortedFields($msg);
        $fieldInfo = [];
        $oneofs = [];

        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $type = (int) $f->getType();
            $fieldFeatures = EditionResolver::resolveFieldFeatures($f, $msgFeatures, $oneofDecls);
            $isMap = $this->isMapField($f);
            $isRepeated = !$isMap && (int) $f->getLabel() === Label::LABEL_REPEATED;
            $isMessage = !$isMap && EditionResolver::isMessageType($type, $msgFeatures->isEditions);
            $realOneofIndex = $f->hasOneofIndex() && !$f->getProto3Optional() ? (int) $f->getOneofIndex() : null;
            $explicitPresence = !$isMap && !$isRepeated && !$isMessage
                && EditionResolver::fieldHasExplicitPresence($f, $fieldFeatures);

            $fieldInfo[$num] = [
                'field' => $f,
                'features' => $fieldFeatures,
                'explicitPresence' => $explicitPresence,
                'realOneofIndex' => $realOneofIndex,
                'isMessage' => $isMessage,
                'isMap' => $isMap,
                'isRepeated' => $isRepeated,
            ];

            if ($realOneofIndex !== null) {
                if (!isset($oneofs[$realOneofIndex])) {
                    $oneofName = isset($oneofDecls[$realOneofIndex])
                        ? (string) $oneofDecls[$realOneofIndex]->getName()
                        : "oneof_{$realOneofIndex}";
                    $oneofs[$realOneofIndex] = [
                        'index' => $realOneofIndex,
                        'name' => $oneofName,
                        'pascal' => $this->pascalCase($oneofName),
                        'upper' => strtoupper($oneofName),
                        'fields' => [],
                        'wireTypes' => [],
                    ];
                }
                $oneofs[$realOneofIndex]['fields'][] = $f;
                $oneofs[$realOneofIndex]['wireTypes'][$num] = $this->protoTypeToWireType($type);
            }
        }

        ksort($oneofs, SORT_NUMERIC);

        return [
            'fields' => $fields,
            'fieldInfo' => $fieldInfo,
            'oneofs' => $oneofs,
        ];
    }

    private function renderMessageClass(
        DescriptorProto $msg,
        string $fullName,
        string $ns,
        ResolvedFeatures $msgFeatures,
    ): string {
        $className = (string) $msg->getName();
        $analysis = $this->analyzeMessage($msg, $msgFeatures);
        $fields = $analysis['fields'];
        $fieldInfo = $analysis['fieldInfo'];
        $oneofs = $analysis['oneofs'];

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        if ($ns !== '') {
            $lines[] = "namespace {$ns};";
            $lines[] = '';
        }
        $lines[] = 'use OmniBuf\\Decoder;';
        $lines[] = 'use OmniBuf\\Encoder;';
        $lines[] = 'use OmniBuf\\GeneratedMessage;';
        $lines[] = 'use OmniBuf\\SchemaMeta;';
        $lines[] = 'use OmniBuf\\UInt64;';
        $lines[] = 'use OmniBuf\\Wire;';
        $lines[] = 'use OmniBuf\\WireType;';
        $lines[] = '';
        $lines[] = "final class {$className} implements GeneratedMessage";
        $lines[] = '{';
        $lines[] = '    private static int $nativeSchemaId = -2;';
        $lines[] = '';

        // Oneof case constants
        foreach ($oneofs as $oneof) {
            $lines[] = "    public const int {$oneof['upper']}_NOT_SET = 0;";
            foreach ($oneof['fields'] as $of) {
                $ofUpper = strtoupper((string) $of->getName());
                $ofNum = (int) $of->getNumber();
                $lines[] = "    public const int {$oneof['upper']}_{$ofUpper} = {$ofNum};";
            }
            $lines[] = '';
        }

        // Properties
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $prop = $this->camelCase((string) $f->getName());
            [$phpType, $default, $doc] = $this->phpPropSpec($f, $fieldInfo[$num]['explicitPresence'], $msgFeatures->isEditions);
            if ($doc !== null) {
                $lines[] = "    /** @var {$doc} */";
            }
            $lines[] = "    public {$phpType} \${$prop} = {$default};";
            $lines[] = '';
        }

        // Constructor
        $lines[] = '    public function __construct(';
        $ctorParams = [];
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $prop = $this->camelCase((string) $f->getName());
            [$phpType] = $this->phpPropSpec($f, $fieldInfo[$num]['explicitPresence'], $msgFeatures->isEditions);
            $nullableType = str_starts_with($phpType, '?') ? $phpType : "?{$phpType}";
            $ctorParams[] = "        {$nullableType} \${$prop} = null,";
        }
        if ($ctorParams !== []) {
            foreach ($ctorParams as $p) {
                $lines[] = $p;
            }
            $lines[] = '    ) {';
            foreach ($fields as $f) {
                $num = (int) $f->getNumber();
                $prop = $this->camelCase((string) $f->getName());
                if ($fieldInfo[$num]['realOneofIndex'] !== null) {
                    $pascal = $this->pascalCase((string) $f->getName());
                    $lines[] = "        if (\${$prop} !== null) { \$this->set{$pascal}(\${$prop}); }";
                } else {
                    $lines[] = "        if (\${$prop} !== null) { \$this->{$prop} = \${$prop}; }";
                }
            }
            $lines[] = '    }';
        } else {
            $lines[array_key_last($lines)] = '    public function __construct() {}';
        }
        $lines[] = '';

        // Oneof case / clear / setter helpers
        foreach ($oneofs as $oneof) {
            $lines[] = "    public function get{$oneof['pascal']}Case(): int";
            $lines[] = '    {';
            foreach ($oneof['fields'] as $of) {
                $ofProp = $this->camelCase((string) $of->getName());
                $ofNum = (int) $of->getNumber();
                $lines[] = "        if (\$this->{$ofProp} !== null) { return {$ofNum}; }";
            }
            $lines[] = '        return 0;';
            $lines[] = '    }';
            $lines[] = '';

            $lines[] = "    public function clear{$oneof['pascal']}(): self";
            $lines[] = '    {';
            foreach ($oneof['fields'] as $of) {
                $ofProp = $this->camelCase((string) $of->getName());
                $lines[] = "        \$this->{$ofProp} = null;";
            }
            $lines[] = '        return $this;';
            $lines[] = '    }';
            $lines[] = '';

            foreach ($oneof['fields'] as $of) {
                $ofNum = (int) $of->getNumber();
                $ofProp = $this->camelCase((string) $of->getName());
                $ofPascal = $this->pascalCase((string) $of->getName());
                [$ofType] = $this->phpPropSpec($of, $fieldInfo[$ofNum]['explicitPresence'], $msgFeatures->isEditions);
                $lines[] = "    public function set{$ofPascal}({$ofType} \$value): self";
                $lines[] = '    {';
                foreach ($oneof['fields'] as $sib) {
                    if ((int) $sib->getNumber() === $ofNum) {
                        continue;
                    }
                    $sibProp = $this->camelCase((string) $sib->getName());
                    $lines[] = "        \$this->{$sibProp} = null;";
                }
                $lines[] = "        \$this->{$ofProp} = \$value;";
                $lines[] = '        return $this;';
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        // marshalOmnibuf
        $lines[] = '    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string';
        $lines[] = '    {';
        $lines[] = '        if (!$selfDescribing) {';
        $lines[] = '            $schemaId = self::$nativeSchemaId;';
        $lines[] = '            if ($schemaId >= 0) {';
        $lines[] = '                return \omnibuf_native_encode($schemaId, $this, $deterministic);';
        $lines[] = '            }';
        $lines[] = '            if ($schemaId === -2) {';
        $lines[] = '                $resolved = self::nativeSchemaId();';
        $lines[] = '                if ($resolved !== null) {';
        $lines[] = '                    return \omnibuf_native_encode($resolved, $this, $deterministic);';
        $lines[] = '                }';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        $enc = new Encoder();';
        $lines[] = '        if ($selfDescribing) {';
        $lines[] = '            self::schemaMeta()->writeTo($enc, true);';
        $lines[] = '        }';
        $lines[] = '        $this->marshalTo($enc, $deterministic);';
        $lines[] = '        return $enc->finish();';
        $lines[] = '    }';
        $lines[] = '';

        // marshalTo (hot-path inlined!)
        $lines[] = '    public function marshalTo(Encoder $enc, bool $deterministic = false): void';
        $lines[] = '    {';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $info = $fieldInfo[$num];
            $oneofPascal = $info['realOneofIndex'] !== null ? $oneofs[$info['realOneofIndex']]['pascal'] : null;
            $this->emitMarshalField($f, $info['explicitPresence'], $info['isMessage'], $oneofPascal, $msgFeatures->isEditions, $lines);
        }
        $lines[] = '    }';
        $lines[] = '';

        // unmarshalOmnibuf
        $lines[] = '    public static function unmarshalOmnibuf(';
        $lines[] = '        string $data,';
        $lines[] = '        int $maxDepth = Wire::MAX_NESTING_DEPTH,';
        $lines[] = '        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,';
        $lines[] = '    ): static {';
        $lines[] = '        $schemaId = self::$nativeSchemaId;';
        $lines[] = '        if ($schemaId >= 0) {';
        $lines[] = '            /** @var static $msg */';
        $lines[] = '            $msg = \omnibuf_native_decode($schemaId, $data, $maxDepth, $maxElements);';
        $lines[] = '            return $msg;';
        $lines[] = '        }';
        $lines[] = '        if ($schemaId === -2) {';
        $lines[] = '            $resolved = self::nativeSchemaId();';
        $lines[] = '            if ($resolved !== null) {';
        $lines[] = '                /** @var static $msg */';
        $lines[] = '                $msg = \omnibuf_native_decode($resolved, $data, $maxDepth, $maxElements);';
        $lines[] = '                return $msg;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '        $msg = new self();';
        $lines[] = '        $msg->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), false);';
        $lines[] = '        return $msg;';
        $lines[] = '    }';
        $lines[] = '';

        $this->emitNativeSchemaMethod($fullName, $fields, $fieldInfo, $oneofs !== [], $msgFeatures->isEditions, $lines);

        // unmarshalInto
        $lines[] = '    public function unmarshalInto(';
        $lines[] = '        string $data,';
        $lines[] = '        bool $merge = false,';
        $lines[] = '        int $maxDepth = Wire::MAX_NESTING_DEPTH,';
        $lines[] = '        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,';
        $lines[] = '    ): void {';
        $lines[] = '        $this->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), $merge);';
        $lines[] = '    }';
        $lines[] = '';

        // decodeFrom
        $lines[] = '    public function decodeFrom(Decoder $dec, bool $merge = false): void';
        $lines[] = '    {';
        $lines[] = '        if (!$merge) {';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $prop = $this->camelCase((string) $f->getName());
            [, $default] = $this->phpPropSpec($f, $fieldInfo[$num]['explicitPresence'], $msgFeatures->isEditions);
            $lines[] = "            \$this->{$prop} = {$default};";
        }
        $lines[] = '        }';
        $lines[] = '        $buf = $dec->buf;';
        $lines[] = '        $end = $dec->end;';
        $lines[] = '        while ($dec->pos < $end) {';
        $lines[] = '            $b0 = \ord($buf[$dec->pos]);';
        $lines[] = '            if (($b0 & 1) !== 0) {';
        $lines[] = '                $tag = $b0 >> 1;';
        $lines[] = '                $dec->pos++;';
        $lines[] = '                $fieldNum = $tag >> 4;';
        $lines[] = '                $wireType = $tag & 0x0F;';
        $lines[] = '                if ($fieldNum === 0 && $wireType !== WireType::SUB_MSG) {';
        $lines[] = '                    throw new \\OmniBuf\\Exception\\DecodeException("omnibuf: field #0 must use WireSubMsg");';
        $lines[] = '                }';
        $lines[] = '            } else {';
        $lines[] = '                [$fieldNum, $wireType] = $dec->readTag();';
        $lines[] = '            }';
        $lines[] = '            if ($fieldNum === 0) {';
        $lines[] = '                $dec->readField0();';
        $lines[] = '                continue;';
        $lines[] = '            }';
        $lines[] = '            switch ($fieldNum) {';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $info = $fieldInfo[$num];
            $siblingProps = [];
            if ($info['realOneofIndex'] !== null) {
                foreach ($oneofs[$info['realOneofIndex']]['fields'] as $sib) {
                    if ((int) $sib->getNumber() !== $num) {
                        $siblingProps[] = $this->camelCase((string) $sib->getName());
                    }
                }
            }
            $this->emitDecodeFieldCase($f, $info['isMessage'], $siblingProps, $msgFeatures->isEditions, $lines);
        }
        $lines[] = '                default:';
        $lines[] = '                    $dec->skipUnknown($wireType);';
        $lines[] = '                    break;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';

        // schemaMeta
        $lines[] = '    /** @param array<class-string, true> $visited */';
        $lines[] = '    public static function schemaMeta(array $visited = []): SchemaMeta';
        $lines[] = '    {';
        $lines[] = '        $visited[self::class] = true;';
        $lines[] = '        $children = [];';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $childClass = $this->childMessageClass($f, $msgFeatures->isEditions);
            if ($childClass !== null) {
                $lines[] = "        if (!isset(\$visited[{$childClass}::class])) {";
                $lines[] = "            \$children[{$num}] = {$childClass}::schemaMeta(\$visited);";
                $lines[] = '        }';
            }
        }
        $lines[] = '        return new SchemaMeta(';
        $lines[] = '            ' . var_export($fullName, true) . ',';
        $lines[] = '            [';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $fname = (string) $f->getName();
            $lines[] = "                {$num} => " . var_export($fname, true) . ',';
        }
        $lines[] = '            ],';
        $lines[] = '            $children,';
        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderOpaqueClass(DescriptorProto $msg, string $ns, ResolvedFeatures $msgFeatures): string
    {
        $className = (string) $msg->getName();
        $opaqueName = "Opaque{$className}";
        $analysis = $this->analyzeMessage($msg, $msgFeatures);
        $fields = $analysis['fields'];
        $fieldInfo = $analysis['fieldInfo'];
        $oneofs = $analysis['oneofs'];

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        if ($ns !== '') {
            $lines[] = "namespace {$ns};";
            $lines[] = '';
        }
        $lines[] = 'use OmniBuf\\Decoder;';
        $lines[] = 'use OmniBuf\\Encoder;';
        $lines[] = 'use OmniBuf\\Opaque;';
        $lines[] = 'use OmniBuf\\UInt64;';
        $lines[] = 'use OmniBuf\\Wire;';
        $lines[] = 'use OmniBuf\\WireType;';
        $lines[] = '';
        $lines[] = "final class {$opaqueName}";
        $lines[] = '{';

        // Oneof case constants
        foreach ($oneofs as $oneof) {
            $lines[] = "    public const int {$oneof['upper']}_NOT_SET = 0;";
            foreach ($oneof['fields'] as $of) {
                $ofUpper = strtoupper((string) $of->getName());
                $ofNum = (int) $of->getNumber();
                $lines[] = "    public const int {$oneof['upper']}_{$ofUpper} = {$ofNum};";
            }
            $lines[] = '';
        }

        $lines[] = '    /** @var array<int, string> */';
        $lines[] = '    private array $dirty = [];';
        $lines[] = '';
        $lines[] = '    /** @var array<int, mixed> */';
        $lines[] = '    private array $dirtyValues = [];';
        $lines[] = '';
        $lines[] = '    public function __construct(';
        $lines[] = '        private readonly string $raw,';
        $lines[] = '    ) {}';
        $lines[] = '';
        $lines[] = '    public function marshal(): string';
        $lines[] = '    {';
        $lines[] = '        return Wire::spliceFields(\'\', $this->raw, $this->dirty);';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    public function marshalAppend(string $prefix = \'\'): string';
        $lines[] = '    {';
        $lines[] = '        return Wire::spliceFields($prefix, $this->raw, $this->dirty);';
        $lines[] = '    }';
        $lines[] = '';

        // Oneof case & clear helpers on Opaque view
        foreach ($oneofs as $oneof) {
            $membersLit = $this->formatIntMapLiteral($oneof['wireTypes']);
            $firstNum = (int) $oneof['fields'][0]->getNumber();
            $lines[] = "    public function get{$oneof['pascal']}Case(): int";
            $lines[] = '    {';
            $lines[] = "        if (\\array_key_exists({$firstNum}, \$this->dirtyValues)) {";
            foreach ($oneof['fields'] as $of) {
                $ofNum = (int) $of->getNumber();
                $lines[] = "            if (\$this->dirtyValues[{$ofNum}] !== null) { return {$ofNum}; }";
            }
            $lines[] = '            return 0;';
            $lines[] = '        }';
            $lines[] = "        \$hit = Opaque::locateLastOneof(\$this->raw, 0, \\strlen(\$this->raw), {$membersLit});";
            $lines[] = '        return $hit !== null ? $hit[0] : 0;';
            $lines[] = '    }';
            $lines[] = '';

            $lines[] = "    public function clear{$oneof['pascal']}(): void";
            $lines[] = '    {';
            foreach ($oneof['fields'] as $of) {
                $ofNum = (int) $of->getNumber();
                $lines[] = "        \$this->dirtyValues[{$ofNum}] = null;";
                $lines[] = "        \$this->dirty[{$ofNum}] = '';";
            }
            $lines[] = '    }';
            $lines[] = '';
        }

        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $info = $fieldInfo[$num];
            $oneofGroup = $info['realOneofIndex'] !== null ? $oneofs[$info['realOneofIndex']] : null;
            $this->emitOpaqueAccessors($f, $info['explicitPresence'], $info['isMessage'], $oneofGroup, $msgFeatures->isEditions, $lines);
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function emitMarshalField(
        FieldDescriptorProto $f,
        bool $explicitPresence,
        bool $isMessage,
        ?string $oneofPascal,
        bool $isEditions,
        array &$lines,
    ): void {
        $num = (int) $f->getNumber();
        $prop = $this->camelCase((string) $f->getName());
        $type = (int) $f->getType();

        if ($this->isMapField($f)) {
            [$keyF, $valF] = $this->mapKeyValFields($f);
            $kWt = $this->protoTypeToWireType((int) $keyF->getType());
            $vWt = $this->protoTypeToWireType((int) $valF->getType());
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::MAP));
            $lines[] = "        if (\$this->{$prop} !== []) {";
            $lines[] = "            \$entries = \$this->{$prop};";
            $lines[] = '            if ($deterministic) {';
            $lines[] = $this->mapSortExpr('$entries', $kWt, '                ');
            $lines[] = '            }';
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = '            $enc->buf .= ' . $this->phpStringLiteral(\chr($kWt) . \chr($vWt)) . ';';
            $lines[] = '            $enc->writeVarint(\count($entries));';
            $lines[] = '            foreach ($entries as $k => $v) {';
            $lines[] = '                ' . $this->emitWriteItem('$k', $keyF, $isEditions);
            $lines[] = '                ' . $this->emitWriteItem('$v', $valF, $isEditions);
            $lines[] = '            }';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
            return;
        }

        if ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::ARRAY));
            $lines[] = "        if (\$this->{$prop} !== []) {";
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = '            $enc->buf .= ' . $this->phpStringLiteral(\chr($eWt)) . ';';
            $lines[] = "            \$enc->writeVarint(\\count(\$this->{$prop}));";
            $lines[] = "            foreach (\$this->{$prop} as \$item) {";
            $lines[] = '                ' . $this->emitWriteItem('$item', $f, $isEditions);
            $lines[] = '            }';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
            return;
        }

        $presenceCond = $oneofPascal !== null
            ? "\$this->get{$oneofPascal}Case() === {$num} && \$this->{$prop} !== null"
            : "\$this->{$prop} !== null";

        if ($isMessage) {
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::SUB_MSG));
            $lines[] = "        if ({$presenceCond}) {";
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = "            \$this->{$prop}->marshalTo(\$enc, \$deterministic);";
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
            return;
        }

        if ($type === Type::TYPE_BOOL) {
            $trueTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_TRUE));
            if ($explicitPresence) {
                $falseTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_FALSE));
                $lines[] = "        if ({$presenceCond}) {";
                $lines[] = "            \$enc->buf .= \$this->{$prop} ? {$trueTag} : {$falseTag};";
                $lines[] = '        }';
            } else {
                $lines[] = "        if (\$this->{$prop}) {";
                $lines[] = "            \$enc->buf .= {$trueTag};";
                $lines[] = '        }';
            }
            return;
        }

        $wt = $this->protoTypeToWireType($type);
        $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, $wt));

        if ($explicitPresence) {
            $lines[] = "        if ({$presenceCond}) {";
            switch ($type) {
                case Type::TYPE_UINT32:
                    $lines[] = "            \$enc->buf .= {$tagBytes};";
                    $lines[] = "            \$enc->writeVarint(\$this->{$prop} & 0xFFFFFFFF);";
                    break;
                case Type::TYPE_UINT64:
                    $lines[] = "            \$enc->buf .= {$tagBytes};";
                    $lines[] = "            \$enc->writeVarint(\$this->{$prop});";
                    break;
                case Type::TYPE_INT32:
                case Type::TYPE_SINT32:
                case Type::TYPE_ENUM:
                    $lines[] = "            \$enc->buf .= {$tagBytes};";
                    $lines[] = "            \$n = (\$this->{$prop} << 32) >> 32;";
                    $lines[] = '            $enc->writeVarint(($n << 1) ^ ($n >> 63));';
                    break;
                case Type::TYPE_INT64:
                case Type::TYPE_SINT64:
                    $lines[] = "            \$enc->buf .= {$tagBytes};";
                    $lines[] = "            \$enc->writeVarint((\$this->{$prop} << 1) ^ (\$this->{$prop} >> 63));";
                    break;
                case Type::TYPE_FIXED32:
                case Type::TYPE_SFIXED32:
                    $lines[] = "            \$enc->buf .= {$tagBytes} . pack('V', \$this->{$prop} & 0xFFFFFFFF);";
                    break;
                case Type::TYPE_FIXED64:
                case Type::TYPE_SFIXED64:
                    $lines[] = "            \$enc->buf .= {$tagBytes} . pack('P', \$this->{$prop});";
                    break;
                case Type::TYPE_FLOAT:
                    $lines[] = "            \$enc->buf .= {$tagBytes} . pack('g', \$this->{$prop});";
                    break;
                case Type::TYPE_DOUBLE:
                    $lines[] = "            \$enc->buf .= {$tagBytes} . pack('e', \$this->{$prop});";
                    break;
                case Type::TYPE_STRING:
                case Type::TYPE_BYTES:
                    $lines[] = "            \$enc->buf .= {$tagBytes};";
                    $lines[] = "            \$enc->writeVarint(\\strlen(\$this->{$prop}));";
                    $lines[] = "            \$enc->buf .= \$this->{$prop};";
                    break;
            }
            $lines[] = '        }';
            return;
        }

        switch ($type) {
            case Type::TYPE_UINT32:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes};";
                $lines[] = "            \$enc->writeVarint(\$this->{$prop} & 0xFFFFFFFF);";
                $lines[] = '        }';
                break;
            case Type::TYPE_UINT64:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes};";
                $lines[] = "            \$enc->writeVarint(\$this->{$prop});";
                $lines[] = '        }';
                break;
            case Type::TYPE_INT32:
            case Type::TYPE_SINT32:
            case Type::TYPE_ENUM:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes};";
                $lines[] = "            \$n = (\$this->{$prop} << 32) >> 32;";
                $lines[] = '            $enc->writeVarint(($n << 1) ^ ($n >> 63));';
                $lines[] = '        }';
                break;
            case Type::TYPE_INT64:
            case Type::TYPE_SINT64:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes};";
                $lines[] = "            \$enc->writeVarint((\$this->{$prop} << 1) ^ (\$this->{$prop} >> 63));";
                $lines[] = '        }';
                break;
            case Type::TYPE_FIXED32:
            case Type::TYPE_SFIXED32:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes} . pack('V', \$this->{$prop} & 0xFFFFFFFF);";
                $lines[] = '        }';
                break;
            case Type::TYPE_FIXED64:
            case Type::TYPE_SFIXED64:
                $lines[] = "        if (\$this->{$prop} !== 0) {";
                $lines[] = "            \$enc->buf .= {$tagBytes} . pack('P', \$this->{$prop});";
                $lines[] = '        }';
                break;
            case Type::TYPE_FLOAT:
                $lines[] = "        if (\$this->{$prop} !== 0.0 || pack('g', \$this->{$prop}) !== \"\\x00\\x00\\x00\\x00\") {";
                $lines[] = "            \$enc->buf .= {$tagBytes} . pack('g', \$this->{$prop});";
                $lines[] = '        }';
                break;
            case Type::TYPE_DOUBLE:
                $lines[] = "        if (\$this->{$prop} !== 0.0 || pack('e', \$this->{$prop}) !== \"\\x00\\x00\\x00\\x00\\x00\\x00\\x00\\x00\") {";
                $lines[] = "            \$enc->buf .= {$tagBytes} . pack('e', \$this->{$prop});";
                $lines[] = '        }';
                break;
            case Type::TYPE_STRING:
            case Type::TYPE_BYTES:
                $lines[] = "        if (\$this->{$prop} !== '') {";
                $lines[] = "            \$enc->buf .= {$tagBytes};";
                $lines[] = "            \$enc->writeVarint(\\strlen(\$this->{$prop}));";
                $lines[] = "            \$enc->buf .= \$this->{$prop};";
                $lines[] = '        }';
                break;
        }
    }

    /**
     * @param list<string> $siblingProps
     * @param list<string> $lines
     */
    private function emitDecodeFieldCase(
        FieldDescriptorProto $f,
        bool $isMessage,
        array $siblingProps,
        bool $isEditions,
        array &$lines,
    ): void {
        $num = (int) $f->getNumber();
        $prop = $this->camelCase((string) $f->getName());
        $type = (int) $f->getType();

        $lines[] = "                case {$num}:";

        if ($this->isMapField($f)) {
            [$keyF, $valF] = $this->mapKeyValFields($f);
            $kWt = $this->protoTypeToWireType((int) $keyF->getType());
            $vWt = $this->protoTypeToWireType((int) $valF->getType());
            $lines[] = '                    if ($wireType !== WireType::MAP) { $dec->skipUnknown($wireType); break; }';
            $lines[] = "                    [\$mapDec, \$_kt, \$_vt, \$count] = \$dec->readMapHeader({$kWt}, {$vWt});";
            $lines[] = '                    for ($i = 0; $i < $count; $i++) {';
            $lines[] = '                        $k = ' . $this->emitReadItem('$mapDec', $keyF, $isEditions) . ';';
            $lines[] = "                        \$this->{$prop}[\$k] = " . $this->emitReadItem('$mapDec', $valF, $isEditions) . ';';
            $lines[] = '                    }';
            $lines[] = '                    $mapDec->assertAtEnd(\'WireMap\');';
            $lines[] = '                    break;';
            return;
        }

        if ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $lines[] = '                    if ($wireType !== WireType::ARRAY) { $dec->skipUnknown($wireType); break; }';
            $lines[] = "                    [\$arrDec, \$_et, \$count] = \$dec->readArrayHeader({$eWt});";
            $lines[] = '                    for ($i = 0; $i < $count; $i++) {';
            $lines[] = "                        \$this->{$prop}[] = " . $this->emitReadItem('$arrDec', $f, $isEditions) . ';';
            $lines[] = '                    }';
            $lines[] = '                    $arrDec->assertAtEnd(\'WireArray\');';
            $lines[] = '                    break;';
            return;
        }

        if ($type === Type::TYPE_BOOL) {
            $lines[] = '                    if ($wireType === WireType::BOOL_FALSE) {';
            foreach ($siblingProps as $sib) {
                $lines[] = "                        \$this->{$sib} = null;";
            }
            $lines[] = "                        \$this->{$prop} = false;";
            $lines[] = '                    } elseif ($wireType === WireType::BOOL_TRUE) {';
            foreach ($siblingProps as $sib) {
                $lines[] = "                        \$this->{$sib} = null;";
            }
            $lines[] = "                        \$this->{$prop} = true;";
            $lines[] = '                    } else {';
            $lines[] = '                        $dec->skipUnknown($wireType);';
            $lines[] = '                    }';
            $lines[] = '                    break;';
            return;
        }

        $wt = $this->protoTypeToWireType($type);
        $lines[] = "                    if (\$wireType !== {$wt}) { \$dec->skipUnknown(\$wireType); break; }";

        foreach ($siblingProps as $sib) {
            $lines[] = "                    \$this->{$sib} = null;";
        }

        if ($isMessage) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            $lines[] = '                    $subDec = $dec->readSubMessageDecoder();';
            $lines[] = "                    if (\$this->{$prop} === null) {";
            $lines[] = "                        \$this->{$prop} = new {$subClass}();";
            $lines[] = "                        \$this->{$prop}->decodeFrom(\$subDec, false);";
            $lines[] = '                    } else {';
            $lines[] = "                        \$this->{$prop}->decodeFrom(\$subDec, true);";
            $lines[] = '                    }';
            $lines[] = '                    break;';
            return;
        }

        $lines[] = "                    \$this->{$prop} = " . $this->emitReadItem('$dec', $f, $isEditions) . ';';
        $lines[] = '                    break;';
    }

    /**
     * @param ?array{index: int, name: string, pascal: string, upper: string, fields: list<FieldDescriptorProto>, wireTypes: array<int, int>} $oneofGroup
     * @param list<string> $lines
     */
    private function emitOpaqueAccessors(
        FieldDescriptorProto $f,
        bool $explicitPresence,
        bool $isMessage,
        ?array $oneofGroup,
        bool $isEditions,
        array &$lines,
    ): void {
        $num = (int) $f->getNumber();
        $pascal = $this->pascalCase((string) $f->getName());
        $type = (int) $f->getType();
        [$phpType, , $doc] = $this->phpPropSpec($f, $explicitPresence, $isEditions);

        // Getter
        if ($doc !== null) {
            $lines[] = "    /** @return {$doc} */";
        }
        $lines[] = "    public function get{$pascal}(): {$phpType}";
        $lines[] = '    {';
        $lines[] = "        if (\\array_key_exists({$num}, \$this->dirtyValues)) {";
        $lines[] = "            return \$this->dirtyValues[{$num}];";
        $lines[] = '        }';

        if ($this->isMapField($f)) {
            [$keyF, $valF] = $this->mapKeyValFields($f);
            $kWt = $this->protoTypeToWireType((int) $keyF->getType());
            $vWt = $this->protoTypeToWireType((int) $valF->getType());
            $lines[] = "        \$ranges = Opaque::locateAllRawRanges(\$this->raw, 0, \\strlen(\$this->raw), {$num}, WireType::MAP);";
            $lines[] = '        $out = [];';
            $lines[] = '        foreach ($ranges as [$s, $e]) {';
            $lines[] = '            $dec = new Decoder($this->raw, $s, $e, 0);';
            $lines[] = "            [\$mapDec, \$_kt, \$_vt, \$count] = \$dec->readMapHeader({$kWt}, {$vWt});";
            $lines[] = '            if (\count($out) + $count > $mapDec->maxElements) {';
            $lines[] = '                throw new \\OmniBuf\\Exception\\DecodeException(\'omnibuf: WireMap count exceeds maxElements\');';
            $lines[] = '            }';
            $lines[] = '            for ($i = 0; $i < $count; $i++) {';
            $lines[] = '                $k = ' . $this->emitReadItem('$mapDec', $keyF, $isEditions) . ';';
            $lines[] = '                $out[$k] = ' . $this->emitReadItem('$mapDec', $valF, $isEditions) . ';';
            $lines[] = '            }';
            $lines[] = '            $mapDec->assertAtEnd(\'WireMap\');';
            $lines[] = '        }';
            $lines[] = '        return $out;';
        } elseif ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $lines[] = "        \$ranges = Opaque::locateAllRawRanges(\$this->raw, 0, \\strlen(\$this->raw), {$num}, WireType::ARRAY);";
            $lines[] = '        $out = [];';
            $lines[] = '        foreach ($ranges as [$s, $e]) {';
            $lines[] = '            $dec = new Decoder($this->raw, $s, $e, 0);';
            $lines[] = "            [\$arrDec, \$_et, \$count] = \$dec->readArrayHeader({$eWt});";
            $lines[] = '            if (\count($out) + $count > $arrDec->maxElements) {';
            $lines[] = '                throw new \\OmniBuf\\Exception\\DecodeException(\'omnibuf: WireArray count exceeds maxElements\');';
            $lines[] = '            }';
            $lines[] = '            for ($i = 0; $i < $count; $i++) {';
            $lines[] = '                $out[] = ' . $this->emitReadItem('$arrDec', $f, $isEditions) . ';';
            $lines[] = '            }';
            $lines[] = '            $arrDec->assertAtEnd(\'WireArray\');';
            $lines[] = '        }';
            $lines[] = '        return $out;';
        } elseif ($oneofGroup !== null) {
            $membersLit = $this->formatIntMapLiteral($oneofGroup['wireTypes']);
            $lines[] = "        \$hit = Opaque::locateLastOneof(\$this->raw, 0, \\strlen(\$this->raw), {$membersLit});";
            $lines[] = "        if (\$hit === null || \$hit[0] !== {$num}) { return null; }";
            if ($isMessage) {
                $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
                $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, \$hit[1], \\strlen(\$this->raw), {$num});";
                $lines[] = '        if ($slice === null) { return null; }';
                $lines[] = "        return {$subClass}::unmarshalOmnibuf(\$slice);";
            } else {
                $helper = $this->opaqueScalarHelper($type, true);
                $lines[] = "        return Opaque::{$helper}(\$this->raw, \$hit[1], \\strlen(\$this->raw), {$num});";
            }
        } elseif ($isMessage) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
            $lines[] = '        if ($slice === null) { return null; }';
            $lines[] = "        return {$subClass}::unmarshalOmnibuf(\$slice);";
        } else {
            $helper = $this->opaqueScalarHelper($type, $explicitPresence);
            $lines[] = "        return Opaque::{$helper}(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
        }
        $lines[] = '    }';
        $lines[] = '';

        // Extra lazy opaque<SubMsg>() view for singular message fields
        if ($isMessage && (int) $f->getLabel() !== Label::LABEL_REPEATED && !$this->isMapField($f)) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            $lastSlash = strrpos($subClass, '\\');
            $subOpaque = $lastSlash !== false
                ? substr($subClass, 0, $lastSlash + 1) . 'Opaque' . substr($subClass, $lastSlash + 1)
                : 'Opaque' . $subClass;
            $lines[] = "    public function opaque{$pascal}(): ?{$subOpaque}";
            $lines[] = '    {';
            $lines[] = "        if (\\array_key_exists({$num}, \$this->dirtyValues)) {";
            $lines[] = "            \$v = \$this->dirtyValues[{$num}];";
            $lines[] = '            if ($v === null) { return null; }';
            $lines[] = "            return new {$subOpaque}(\$v->marshalOmnibuf(true));";
            $lines[] = '        }';
            if ($oneofGroup !== null) {
                $membersLit = $this->formatIntMapLiteral($oneofGroup['wireTypes']);
                $lines[] = "        \$hit = Opaque::locateLastOneof(\$this->raw, 0, \\strlen(\$this->raw), {$membersLit});";
                $lines[] = "        if (\$hit === null || \$hit[0] !== {$num}) { return null; }";
                $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, \$hit[1], \\strlen(\$this->raw), {$num});";
            } else {
                $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
            }
            $lines[] = '        if ($slice === null) { return null; }';
            $lines[] = "        return new {$subOpaque}(\$slice);";
            $lines[] = '    }';
            $lines[] = '';
        }

        // Setter
        if ($doc !== null) {
            $lines[] = "    /** @param {$doc} \$value */";
        }
        $lines[] = "    public function set{$pascal}({$phpType} \$value): void";
        $lines[] = '    {';
        if ($oneofGroup !== null) {
            foreach ($oneofGroup['fields'] as $sib) {
                $sibNum = (int) $sib->getNumber();
                if ($sibNum === $num) {
                    continue;
                }
                $lines[] = "        \$this->dirtyValues[{$sibNum}] = null;";
                $lines[] = "        \$this->dirty[{$sibNum}] = '';";
            }
        }
        $lines[] = "        \$this->dirtyValues[{$num}] = \$value;";
        if ($this->isMapField($f)) {
            [$keyF, $valF] = $this->mapKeyValFields($f);
            $kWt = $this->protoTypeToWireType((int) $keyF->getType());
            $vWt = $this->protoTypeToWireType((int) $valF->getType());
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::MAP));
            $lines[] = '        if ($value === []) {';
            $lines[] = "            \$this->dirty[{$num}] = '';";
            $lines[] = '            return;';
            $lines[] = '        }';
            $lines[] = '        $enc = new Encoder();';
            $lines[] = '        $entries = $value;';
            $lines[] = $this->mapSortExpr('$entries', $kWt, '        ');
            $lines[] = "        \$enc->buf .= {$tagBytes};";
            $lines[] = '        $enc->beginContainer();';
            $lines[] = '        $enc->buf .= ' . $this->phpStringLiteral(\chr($kWt) . \chr($vWt)) . ';';
            $lines[] = '        $enc->writeVarint(\count($entries));';
            $lines[] = '        foreach ($entries as $k => $v) {';
            $lines[] = '            ' . $this->emitWriteItem('$k', $keyF, $isEditions);
            $lines[] = '            ' . $this->emitWriteItem('$v', $valF, $isEditions);
            $lines[] = '        }';
            $lines[] = '        $enc->endContainer();';
            $lines[] = "        \$this->dirty[{$num}] = \$enc->finish();";
        } elseif ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::ARRAY));
            $lines[] = '        if ($value === []) {';
            $lines[] = "            \$this->dirty[{$num}] = '';";
            $lines[] = '            return;';
            $lines[] = '        }';
            $lines[] = '        $enc = new Encoder();';
            $lines[] = "        \$enc->buf .= {$tagBytes};";
            $lines[] = '        $enc->beginContainer();';
            $lines[] = '        $enc->buf .= ' . $this->phpStringLiteral(\chr($eWt)) . ';';
            $lines[] = '        $enc->writeVarint(\count($value));';
            $lines[] = '        foreach ($value as $item) {';
            $lines[] = '            ' . $this->emitWriteItem('$item', $f, $isEditions);
            $lines[] = '        }';
            $lines[] = '        $enc->endContainer();';
            $lines[] = "        \$this->dirty[{$num}] = \$enc->finish();";
        } elseif ($isMessage) {
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::SUB_MSG));
            $lines[] = '        if ($value !== null) {';
            $lines[] = '            $body = $value->marshalOmnibuf(true);';
            $lines[] = "            \$this->dirty[{$num}] = {$tagBytes} . Wire::encodeVarint(\\strlen(\$body)) . \$body;";
            $lines[] = '        } else {';
            $lines[] = "            \$this->dirty[{$num}] = '';";
            $lines[] = '        }';
        } elseif ($type === Type::TYPE_BOOL) {
            $trueTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_TRUE));
            if ($explicitPresence) {
                $falseTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_FALSE));
                $lines[] = "        \$this->dirty[{$num}] = \$value !== null ? (\$value ? {$trueTag} : {$falseTag}) : '';";
            } else {
                $lines[] = "        \$this->dirty[{$num}] = \$value ? {$trueTag} : '';";
            }
        } elseif ($explicitPresence) {
            $wt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, $wt));
            $lines[] = '        if ($value === null) {';
            $lines[] = "            \$this->dirty[{$num}] = '';";
            $lines[] = '            return;';
            $lines[] = '        }';
            $lines[] = "        \$this->dirty[{$num}] = {$tagBytes} . " . $this->emitDirectScalarBytesExpr('$value', $type) . ';';
        } else {
            $wt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, $wt));
            switch ($type) {
                case Type::TYPE_UINT32:
                    $lines[] = '        $u = $value & 0xFFFFFFFF;';
                    $lines[] = "        \$this->dirty[{$num}] = \$u !== 0 ? {$tagBytes} . Wire::encodeVarint(\$u) : '';";
                    break;
                case Type::TYPE_UINT64:
                    $lines[] = "        \$this->dirty[{$num}] = \$value !== 0 ? {$tagBytes} . Wire::encodeVarint(\$value) : '';";
                    break;
                case Type::TYPE_INT32:
                case Type::TYPE_SINT32:
                case Type::TYPE_ENUM:
                    $lines[] = '        $n = ($value << 32) >> 32;';
                    $lines[] = "        \$this->dirty[{$num}] = \$n !== 0 ? {$tagBytes} . Wire::encodeVarint((\$n << 1) ^ (\$n >> 63)) : '';";
                    break;
                case Type::TYPE_INT64:
                case Type::TYPE_SINT64:
                    $lines[] = "        \$this->dirty[{$num}] = \$value !== 0 ? {$tagBytes} . Wire::encodeVarint((\$value << 1) ^ (\$value >> 63)) : '';";
                    break;
                case Type::TYPE_FIXED32:
                case Type::TYPE_SFIXED32:
                    $lines[] = '        $u = $value & 0xFFFFFFFF;';
                    $lines[] = "        \$this->dirty[{$num}] = \$u !== 0 ? {$tagBytes} . pack('V', \$u) : '';";
                    break;
                case Type::TYPE_FIXED64:
                case Type::TYPE_SFIXED64:
                    $lines[] = "        \$this->dirty[{$num}] = \$value !== 0 ? {$tagBytes} . pack('P', \$value) : '';";
                    break;
                case Type::TYPE_FLOAT:
                    $lines[] = '        $p = pack(\'g\', $value);';
                    $lines[] = "        \$this->dirty[{$num}] = \$p !== \"\\x00\\x00\\x00\\x00\" ? {$tagBytes} . \$p : '';";
                    break;
                case Type::TYPE_DOUBLE:
                    $lines[] = "        \$this->dirty[{$num}] = (\$value !== 0.0 || pack('e', \$value) !== \"\\x00\\x00\\x00\\x00\\x00\\x00\\x00\\x00\") ? {$tagBytes} . pack('e', \$value) : '';";
                    break;
                case Type::TYPE_STRING:
                case Type::TYPE_BYTES:
                    $lines[] = "        \$this->dirty[{$num}] = \$value !== '' ? {$tagBytes} . Wire::encodeVarint(\\strlen(\$value)) . \$value : '';";
                    break;
                default:
                    $lines[] = "        \$this->dirty[{$num}] = '';";
                    break;
            }
        }
        $lines[] = '    }';
        $lines[] = '';
    }

    private function emitDirectScalarBytesExpr(string $expr, int $type): string
    {
        return match ($type) {
            Type::TYPE_UINT32 => "Wire::encodeVarint({$expr} & 0xFFFFFFFF)",
            Type::TYPE_UINT64 => "Wire::encodeVarint({$expr})",
            Type::TYPE_INT32, Type::TYPE_SINT32, Type::TYPE_ENUM => "Wire::encodeVarint(((({$expr} << 32) >> 32) << 1) ^ ((({$expr} << 32) >> 32) >> 63))",
            Type::TYPE_INT64, Type::TYPE_SINT64 => "Wire::encodeVarint(({$expr} << 1) ^ ({$expr} >> 63))",
            Type::TYPE_FIXED32, Type::TYPE_SFIXED32 => "pack('V', {$expr} & 0xFFFFFFFF)",
            Type::TYPE_FIXED64, Type::TYPE_SFIXED64 => "pack('P', {$expr})",
            Type::TYPE_FLOAT => "pack('g', {$expr})",
            Type::TYPE_DOUBLE => "pack('e', {$expr})",
            Type::TYPE_STRING, Type::TYPE_BYTES => "Wire::encodeVarint(\\strlen({$expr})) . {$expr}",
            default => "''",
        };
    }

    private function opaqueScalarHelper(int $type, bool $nullable): string
    {
        $base = match ($type) {
            Type::TYPE_UINT32 => 'getUint32',
            Type::TYPE_UINT64 => 'getUint64',
            Type::TYPE_INT32, Type::TYPE_SINT32, Type::TYPE_ENUM => 'getSint32',
            Type::TYPE_INT64, Type::TYPE_SINT64 => 'getSint64',
            Type::TYPE_BOOL => 'getBool',
            Type::TYPE_FIXED32 => 'getFixed32',
            Type::TYPE_FIXED64 => 'getFixed64',
            Type::TYPE_SFIXED32 => 'getSfixed32',
            Type::TYPE_SFIXED64 => 'getSfixed64',
            Type::TYPE_FLOAT => 'getFloat32',
            Type::TYPE_DOUBLE => 'getFloat64',
            Type::TYPE_STRING => 'getString',
            Type::TYPE_BYTES => 'getBytes',
            default => 'getBytes',
        };
        return $nullable ? "{$base}OrNull" : $base;
    }

    /**
     * @param array<int, int> $map
     */
    private function formatIntMapLiteral(array $map): string
    {
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = "{$k} => {$v}";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function emitWriteItem(string $expr, FieldDescriptorProto $f, bool $isEditions): string
    {
        $type = (int) $f->getType();
        if (EditionResolver::isMessageType($type, $isEditions)) {
            return "\$enc->beginContainer(); {$expr}->marshalTo(\$enc, \$deterministic ?? true); \$enc->endContainer();";
        }
        return match ($type) {
            Type::TYPE_UINT32 => "\$enc->writeVarint({$expr} & 0xFFFFFFFF);",
            Type::TYPE_UINT64 => "\$enc->writeVarint({$expr});",
            Type::TYPE_INT32, Type::TYPE_SINT32, Type::TYPE_ENUM => "\$enc->writeSintRaw(({$expr} << 32) >> 32);",
            Type::TYPE_INT64, Type::TYPE_SINT64 => "\$enc->writeSintRaw({$expr});",
            Type::TYPE_BOOL => "\$enc->buf .= {$expr} ? \"\\x01\" : \"\\x00\";",
            Type::TYPE_FIXED32, Type::TYPE_SFIXED32 => "\$enc->buf .= pack('V', {$expr} & 0xFFFFFFFF);",
            Type::TYPE_FIXED64, Type::TYPE_SFIXED64 => "\$enc->buf .= pack('P', {$expr});",
            Type::TYPE_FLOAT => "\$enc->buf .= pack('g', {$expr});",
            Type::TYPE_DOUBLE => "\$enc->buf .= pack('e', {$expr});",
            Type::TYPE_STRING, Type::TYPE_BYTES => "\$enc->writeVarint(\\strlen({$expr})); \$enc->buf .= {$expr};",
            default => '',
        };
    }

    private function emitReadItem(string $decVar, FieldDescriptorProto $f, bool $isEditions): string
    {
        $type = (int) $f->getType();
        if (EditionResolver::isMessageType($type, $isEditions)) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            return "(static function () use ({$decVar}): {$subClass} { \$c = new {$subClass}(); \$c->decodeFrom({$decVar}->readSubMessageDecoder(), false); return \$c; })()";
        }
        return match ($type) {
            Type::TYPE_UINT32 => "{$decVar}->readUint32()",
            Type::TYPE_UINT64 => "{$decVar}->readUint64()",
            Type::TYPE_INT32, Type::TYPE_SINT32, Type::TYPE_ENUM => "{$decVar}->readSint32()",
            Type::TYPE_INT64, Type::TYPE_SINT64 => "{$decVar}->readSint64()",
            Type::TYPE_BOOL => "{$decVar}->readBoolByte()",
            Type::TYPE_FIXED32 => "{$decVar}->readFixed32()",
            Type::TYPE_FIXED64 => "{$decVar}->readFixed64()",
            Type::TYPE_SFIXED32 => "{$decVar}->readSfixed32()",
            Type::TYPE_SFIXED64 => "{$decVar}->readSfixed64()",
            Type::TYPE_FLOAT => "{$decVar}->readFloat32()",
            Type::TYPE_DOUBLE => "{$decVar}->readFloat64()",
            Type::TYPE_STRING => "{$decVar}->readString()",
            Type::TYPE_BYTES => "{$decVar}->readBytes()",
            default => "{$decVar}->readBytes()",
        };
    }

    private function mapSortExpr(string $var, int $keyWt, string $indent): string
    {
        return match ($keyWt) {
            WireType::STRING => "{$indent}uksort({$var}, static fn(int|string \$a, int|string \$b): int => strcmp((string) \$a, (string) \$b));",
            WireType::UINT, WireType::FIXED32, WireType::FIXED64 => "{$indent}uksort({$var}, static fn(int|string \$a, int|string \$b): int => UInt64::compare((int) \$a, (int) \$b));",
            default => "{$indent}uksort({$var}, static fn(int|string \$a, int|string \$b): int => ((int) \$a) <=> ((int) \$b));",
        };
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [phpType, defaultLiteral, phpdocType]
     */
    private function phpPropSpec(FieldDescriptorProto $f, bool $explicitPresence, bool $isEditions): array
    {
        if ($this->isMapField($f)) {
            [$kF, $vF] = $this->mapKeyValFields($f);
            $kType = $this->scalarPhpType((int) $kF->getType());
            $vType = EditionResolver::isMessageType((int) $vF->getType(), $isEditions)
                ? $this->messages[(string) $vF->getTypeName()]['phpClass']
                : $this->scalarPhpType((int) $vF->getType());
            return ['array', '[]', "array<{$kType}, {$vType}>"];
        }
        if ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eType = EditionResolver::isMessageType((int) $f->getType(), $isEditions)
                ? $this->messages[(string) $f->getTypeName()]['phpClass']
                : $this->scalarPhpType((int) $f->getType());
            return ['array', '[]', "list<{$eType}>"];
        }
        $type = (int) $f->getType();
        if (EditionResolver::isMessageType($type, $isEditions)) {
            $cls = $this->messages[(string) $f->getTypeName()]['phpClass'];
            return ["?{$cls}", 'null', null];
        }
        $phpType = $this->scalarPhpType($type);
        if ($explicitPresence) {
            return ["?{$phpType}", 'null', null];
        }
        $default = match ($phpType) {
            'int' => '0',
            'bool' => 'false',
            'float' => '0.0',
            'string' => "''",
            default => 'null',
        };
        return [$phpType, $default, null];
    }

    private function scalarPhpType(int $type): string
    {
        return match ($type) {
            Type::TYPE_BOOL => 'bool',
            Type::TYPE_FLOAT, Type::TYPE_DOUBLE => 'float',
            Type::TYPE_STRING, Type::TYPE_BYTES => 'string',
            default => 'int',
        };
    }

    private function isMapField(FieldDescriptorProto $f): bool
    {
        if ((int) $f->getLabel() !== Label::LABEL_REPEATED || (int) $f->getType() !== Type::TYPE_MESSAGE) {
            return false;
        }
        $typeName = (string) $f->getTypeName();
        return isset($this->messages[$typeName]) && $this->messages[$typeName]['isMapEntry'];
    }

    /**
     * @return array{0: FieldDescriptorProto, 1: FieldDescriptorProto}
     */
    private function mapKeyValFields(FieldDescriptorProto $f): array
    {
        $info = $this->messages[(string) $f->getTypeName()];
        \assert($info['keyField'] !== null && $info['valField'] !== null);
        return [$info['keyField'], $info['valField']];
    }

    private function childMessageClass(FieldDescriptorProto $f, bool $isEditions): ?string
    {
        if ($this->isMapField($f)) {
            [, $valF] = $this->mapKeyValFields($f);
            if (EditionResolver::isMessageType((int) $valF->getType(), $isEditions)) {
                return $this->messages[(string) $valF->getTypeName()]['phpClass'];
            }
            return null;
        }
        if (EditionResolver::isMessageType((int) $f->getType(), $isEditions)) {
            return $this->messages[(string) $f->getTypeName()]['phpClass'];
        }
        return null;
    }

    private function protoTypeToKind(int $type): string
    {
        return match ($type) {
            Type::TYPE_UINT32 => 'uint32',
            Type::TYPE_UINT64 => 'uint64',
            Type::TYPE_INT32, Type::TYPE_SINT32, Type::TYPE_ENUM => 'sint32',
            Type::TYPE_INT64, Type::TYPE_SINT64 => 'sint64',
            Type::TYPE_BOOL => 'bool',
            Type::TYPE_FIXED32 => 'fixed32',
            Type::TYPE_FIXED64 => 'fixed64',
            Type::TYPE_SFIXED32 => 'sfixed32',
            Type::TYPE_SFIXED64 => 'sfixed64',
            Type::TYPE_FLOAT => 'float32',
            Type::TYPE_DOUBLE => 'float64',
            Type::TYPE_STRING => 'string',
            Type::TYPE_BYTES => 'bytes',
            Type::TYPE_MESSAGE, Type::TYPE_GROUP => 'message',
            default => 'bytes',
        };
    }

    /**
     * @param list<FieldDescriptorProto> $fields
     * @param array<int, array{field: FieldDescriptorProto, features: ResolvedFeatures, explicitPresence: bool, realOneofIndex: ?int, isMessage: bool, isMap: bool, isRepeated: bool}> $fieldInfo
     * @param list<string> $lines
     */
    private function emitNativeSchemaMethod(
        string $fullName,
        array $fields,
        array $fieldInfo,
        bool $hasRealOneofs,
        bool $isEditions,
        array &$lines,
    ): void {
        $selfClass = $this->messages['.' . $fullName]['phpClass'] ?? '';
        $hasSelfCycle = false;
        foreach ($fields as $f) {
            $childClass = $this->childMessageClass($f, $isEditions);
            if ($childClass === $selfClass) {
                $hasSelfCycle = true;
                break;
            }
        }

        $lines[] = '    /** @internal */';
        $lines[] = '    public static function nativeSchemaId(): ?int';
        $lines[] = '    {';
        if ($hasSelfCycle || $hasRealOneofs) {
            $lines[] = '        self::$nativeSchemaId = -1;';
            $lines[] = '        return null;';
            $lines[] = '    }';
            $lines[] = '';
            return;
        }
        $lines[] = '        if (self::$nativeSchemaId !== -2) {';
        $lines[] = '            return self::$nativeSchemaId >= 0 ? self::$nativeSchemaId : null;';
        $lines[] = '        }';
        $lines[] = '        self::$nativeSchemaId = -1;';
        $lines[] = '        if (!\function_exists(\'omnibuf_native_register_schema\')) {';
        $lines[] = '            return null;';
        $lines[] = '        }';
        $lines[] = '        $specs = [];';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $info = $fieldInfo[$num];
            $prop = $this->camelCase((string) $f->getName());
            $type = (int) $f->getType();

            if ($info['isMap']) {
                [$keyF, $valF] = $this->mapKeyValFields($f);
                $kk = $this->protoTypeToKind((int) $keyF->getType());
                $vk = $this->protoTypeToKind((int) $valF->getType());
                if (EditionResolver::isMessageType((int) $valF->getType(), $isEditions)) {
                    $childClass = $this->messages[(string) $valF->getTypeName()]['phpClass'];
                    $lines[] = "        \$sub_{$num} = {$childClass}::nativeSchemaId();";
                    $lines[] = "        if (\$sub_{$num} === null) { return null; }";
                    $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => 'map', 'keyKind' => '{$kk}', 'valKind' => 'message', 'subSchemaId' => \$sub_{$num}, 'omitEmpty' => true, 'nullable' => false];";
                } else {
                    $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => 'map', 'keyKind' => '{$kk}', 'valKind' => '{$vk}', 'omitEmpty' => true, 'nullable' => false];";
                }
            } elseif ($info['isRepeated']) {
                $ek = $this->protoTypeToKind($type);
                if (EditionResolver::isMessageType($type, $isEditions)) {
                    $childClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
                    $lines[] = "        \$sub_{$num} = {$childClass}::nativeSchemaId();";
                    $lines[] = "        if (\$sub_{$num} === null) { return null; }";
                    $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => 'array', 'elemKind' => 'message', 'subSchemaId' => \$sub_{$num}, 'omitEmpty' => true, 'nullable' => false];";
                } else {
                    $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => 'array', 'elemKind' => '{$ek}', 'omitEmpty' => true, 'nullable' => false];";
                }
            } elseif ($info['isMessage']) {
                $childClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
                $lines[] = "        \$sub_{$num} = {$childClass}::nativeSchemaId();";
                $lines[] = "        if (\$sub_{$num} === null) { return null; }";
                $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => 'message', 'subSchemaId' => \$sub_{$num}, 'omitEmpty' => true, 'nullable' => true];";
            } else {
                $kind = $this->protoTypeToKind($type);
                $omit = $info['explicitPresence'] ? 'false' : 'true';
                $nullable = $info['explicitPresence'] ? 'true' : 'false';
                $lines[] = "        \$specs[] = ['number' => {$num}, 'prop' => '{$prop}', 'kind' => '{$kind}', 'omitEmpty' => {$omit}, 'nullable' => {$nullable}];";
            }
        }
        $lines[] = '        self::$nativeSchemaId = \omnibuf_native_register_schema(self::class, $specs);';
        $lines[] = '        return self::$nativeSchemaId;';
        $lines[] = '    }';
        $lines[] = '';
    }

    /**
     * @return int<0, 15>
     */
    private function protoTypeToWireType(int $type): int
    {
        return match ($type) {
            Type::TYPE_UINT32, Type::TYPE_UINT64 => WireType::UINT,
            Type::TYPE_INT32, Type::TYPE_INT64, Type::TYPE_SINT32, Type::TYPE_SINT64, Type::TYPE_ENUM => WireType::SINT,
            Type::TYPE_BOOL => WireType::BOOL_FALSE,
            Type::TYPE_FIXED32 => WireType::FIXED32,
            Type::TYPE_FIXED64 => WireType::FIXED64,
            Type::TYPE_SFIXED32 => WireType::SFIXED32,
            Type::TYPE_SFIXED64 => WireType::SFIXED64,
            Type::TYPE_FLOAT => WireType::FLOAT32,
            Type::TYPE_DOUBLE => WireType::FLOAT64,
            Type::TYPE_STRING => WireType::STRING,
            Type::TYPE_BYTES => WireType::BYTES,
            Type::TYPE_MESSAGE, Type::TYPE_GROUP => WireType::SUB_MSG,
            default => WireType::BYTES,
        };
    }

    /**
     * @return list<FieldDescriptorProto>
     */
    private function sortedFields(DescriptorProto $msg): array
    {
        $fields = [];
        foreach ($msg->getField() as $f) {
            /** @var FieldDescriptorProto $f */
            $fields[] = $f;
        }
        usort($fields, static fn(FieldDescriptorProto $a, FieldDescriptorProto $b): int => ((int) $a->getNumber()) <=> ((int) $b->getNumber()));
        return $fields;
    }

    private function pascalCase(string $snake): string
    {
        return implode('', array_map('ucfirst', explode('_', $snake)));
    }

    private function camelCase(string $snake): string
    {
        return lcfirst($this->pascalCase($snake));
    }

    private function phpStringLiteral(string $bytes): string
    {
        $out = '"';
        $len = \strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $out .= \sprintf('\\x%02x', \ord($bytes[$i]));
        }
        return $out . '"';
    }

    // ========================================================================
    // Minimal Protobuf CodeGeneratorRequest / CodeGeneratorResponse Wire Codec
    // ========================================================================

    /**
     * @return array{0: list<string>, 1: string, 2: list<FileDescriptorProto>}
     */
    private function parseCodeGeneratorRequest(string $buf): array
    {
        $filesToGenerate = [];
        $parameter = '';
        $protoFiles = [];

        $pos = 0;
        $end = \strlen($buf);
        while ($pos < $end) {
            $tag = $this->readPbVarint($buf, $pos, $end);
            $fieldNum = $tag >> 3;
            $wireType = $tag & 7;
            if ($wireType === 2) {
                $len = $this->readPbVarint($buf, $pos, $end);
                if ($len < 0 || $len > $end - $pos) {
                    throw new \RuntimeException('Truncated protobuf length-delimited field');
                }
                $slice = substr($buf, $pos, $len);
                $pos += $len;
                if ($fieldNum === 1) {
                    $filesToGenerate[] = $slice;
                } elseif ($fieldNum === 2) {
                    $parameter = $slice;
                } elseif ($fieldNum === 15) {
                    $fdp = new FileDescriptorProto();
                    $fdp->mergeFromString($slice);
                    $protoFiles[] = $fdp;
                }
            } elseif ($wireType === 0) {
                $this->readPbVarint($buf, $pos, $end);
            } elseif ($wireType === 1) {
                if ($end - $pos < 8) {
                    throw new \RuntimeException('Truncated protobuf 64-bit field');
                }
                $pos += 8;
            } elseif ($wireType === 5) {
                if ($end - $pos < 4) {
                    throw new \RuntimeException('Truncated protobuf 32-bit field');
                }
                $pos += 4;
            } else {
                throw new \RuntimeException("Unsupported protobuf wire type {$wireType}");
            }
        }
        return [$filesToGenerate, $parameter, $protoFiles];
    }

    /**
     * @param list<array{name: string, content: string}> $files
     */
    private function encodeCodeGeneratorResponse(array $files, ?string $error): string
    {
        $out = '';
        if ($error !== null) {
            $out .= $this->writePbString(1, $error);
        }
        // Field 2: supported_features = 3 (FEATURE_PROTO3_OPTIONAL | FEATURE_SUPPORTS_EDITIONS)
        $out .= $this->writePbVarint((2 << 3) | 0) . $this->writePbVarint(3);
        // Field 3: minimum_edition = 1000 (EDITION_2023)
        $out .= $this->writePbVarint((3 << 3) | 0) . $this->writePbVarint(EditionResolver::MIN_EDITION);
        // Field 4: maximum_edition = 1002 (EDITION_2026)
        $out .= $this->writePbVarint((4 << 3) | 0) . $this->writePbVarint(EditionResolver::MAX_EDITION);
        if ($error !== null) {
            return $out;
        }
        foreach ($files as $f) {
            $fileBytes = $this->writePbString(1, $f['name']) . $this->writePbString(15, $f['content']);
            $out .= $this->writePbString(15, $fileBytes);
        }
        return $out;
    }

    private function readPbVarint(string $buf, int &$pos, int $end): int
    {
        $result = 0;
        $shift = 0;
        while ($pos < $end) {
            if ($shift >= 64) {
                throw new \RuntimeException('Protobuf varint overflow');
            }
            $b = \ord($buf[$pos++]);
            $result |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
        throw new \RuntimeException('Truncated protobuf varint');
    }

    private function writePbVarint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= \chr(($v & 0x7F) | 0x80);
            $v >>= 7;
        }
        return $out . \chr($v & 0x7F);
    }

    private function writePbString(int $fieldNum, string $data): string
    {
        return $this->writePbVarint(($fieldNum << 3) | 2) . $this->writePbVarint(\strlen($data)) . $data;
    }
}
