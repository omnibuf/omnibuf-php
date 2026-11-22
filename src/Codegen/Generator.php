<?php

declare(strict_types=1);

namespace OmniBuf\Codegen;

use Google\Protobuf\Internal\DescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto;
use Google\Protobuf\Internal\FieldDescriptorProto\Label;
use Google\Protobuf\Internal\FieldDescriptorProto\Type;
use Google\Protobuf\Internal\FileDescriptorProto;
use OmniBuf\Wire;
use OmniBuf\WireType;

/**
 * Protoc plugin code generator (`protoc-gen-omnibuf-php`).
 *
 * Generates typed PHP 8.3+ message classes with inlined single-pass encoders/decoders
 * and lazy zero-decode `Opaque<Message>` views supporting in-place wire splicing.
 */
final class Generator
{
    /** @var array<string, array{phpClass: string, fullName: string, isMapEntry: bool, keyField: ?FieldDescriptorProto, valField: ?FieldDescriptorProto, descriptor: DescriptorProto}> */
    private array $messages = [];

    private string $namespacePrefix = '';

    public function run(string $rawRequest): string
    {
        try {
            [$filesToGenerate, $parameter, $protoFiles] = $this->parseCodeGeneratorRequest($rawRequest);
            $this->parseParameter($parameter);

            foreach ($protoFiles as $file) {
                $this->indexFile($file);
            }

            $generateSet = array_flip($filesToGenerate);
            $outFiles = [];

            foreach ($protoFiles as $file) {
                $fileName = (string) $file->getName();
                if (!isset($generateSet[$fileName])) {
                    continue;
                }
                $ns = $this->phpNamespaceForFile($file);
                $pkg = (string) $file->getPackage();
                foreach ($file->getMessageType() as $msg) {
                    /** @var DescriptorProto $msg */
                    $this->generateMessageRecursive($msg, $pkg, $ns, $outFiles);
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
    private function generateMessageRecursive(DescriptorProto $msg, string $pkg, string $ns, array &$outFiles): void
    {
        $opts = $msg->getOptions();
        if ($opts !== null && (bool) $opts->getMapEntry()) {
            return;
        }
        $name = (string) $msg->getName();
        $fullName = $pkg !== '' ? "{$pkg}.{$name}" : $name;
        $dir = str_replace('\\', '/', $ns);
        $prefix = $dir !== '' ? "{$dir}/" : '';

        $outFiles[] = [
            'name' => "{$prefix}{$name}.php",
            'content' => $this->renderMessageClass($msg, $fullName, $ns),
        ];
        $outFiles[] = [
            'name' => "{$prefix}Opaque{$name}.php",
            'content' => $this->renderOpaqueClass($msg, $ns),
        ];

        foreach ($msg->getNestedType() as $nested) {
            /** @var DescriptorProto $nested */
            $this->generateMessageRecursive($nested, $fullName, $ns !== '' ? "{$ns}\\{$name}" : $name, $outFiles);
        }
    }

    private function renderMessageClass(DescriptorProto $msg, string $fullName, string $ns): string
    {
        $className = (string) $msg->getName();
        $fields = $this->sortedFields($msg);

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

        // Properties
        foreach ($fields as $f) {
            $prop = $this->camelCase((string) $f->getName());
            [$phpType, $default, $doc] = $this->phpPropSpec($f);
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
            $prop = $this->camelCase((string) $f->getName());
            [$phpType] = $this->phpPropSpec($f);
            $nullableType = str_starts_with($phpType, '?') ? $phpType : "?{$phpType}";
            $ctorParams[] = "        {$nullableType} \${$prop} = null,";
        }
        if ($ctorParams !== []) {
            foreach ($ctorParams as $p) {
                $lines[] = $p;
            }
            $lines[] = '    ) {';
            foreach ($fields as $f) {
                $prop = $this->camelCase((string) $f->getName());
                $lines[] = "        if (\${$prop} !== null) { \$this->{$prop} = \${$prop}; }";
            }
            $lines[] = '    }';
        } else {
            $lines[array_key_last($lines)] = '    public function __construct() {}';
        }
        $lines[] = '';

        // marshalOmnibuf
        $lines[] = '    public function marshalOmnibuf(bool $deterministic = false, bool $selfDescribing = false): string';
        $lines[] = '    {';
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
            $this->emitMarshalField($f, $lines);
        }
        $lines[] = '    }';
        $lines[] = '';

        // unmarshalOmnibuf
        $lines[] = '    public static function unmarshalOmnibuf(';
        $lines[] = '        string $data,';
        $lines[] = '        int $maxDepth = Wire::MAX_NESTING_DEPTH,';
        $lines[] = '        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,';
        $lines[] = '    ): static {';
        $lines[] = '        $msg = new self();';
        $lines[] = '        $msg->decodeFrom(new Decoder($data, 0, \strlen($data), 0, $maxDepth, $maxElements), false);';
        $lines[] = '        return $msg;';
        $lines[] = '    }';
        $lines[] = '';

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
            $prop = $this->camelCase((string) $f->getName());
            [, $default] = $this->phpPropSpec($f);
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
            $this->emitDecodeFieldCase($f, $lines);
        }
        $lines[] = '                default:';
        $lines[] = '                    $dec->skipUnknown($wireType);';
        $lines[] = '                    break;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';

        // schemaMeta
        $lines[] = '    public static function schemaMeta(): SchemaMeta';
        $lines[] = '    {';
        $lines[] = '        return new SchemaMeta(';
        $lines[] = '            ' . var_export($fullName, true) . ',';
        $lines[] = '            [';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $fname = (string) $f->getName();
            $lines[] = "                {$num} => " . var_export($fname, true) . ',';
        }
        $lines[] = '            ],';
        $lines[] = '            [';
        foreach ($fields as $f) {
            $num = (int) $f->getNumber();
            $childClass = $this->childMessageClass($f);
            if ($childClass !== null) {
                $lines[] = "                {$num} => {$childClass}::schemaMeta(),";
            }
        }
        $lines[] = '            ],';
        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderOpaqueClass(DescriptorProto $msg, string $ns): string
    {
        $className = (string) $msg->getName();
        $opaqueName = "Opaque{$className}";
        $fields = $this->sortedFields($msg);

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

        foreach ($fields as $f) {
            $this->emitOpaqueAccessors($f, $lines);
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function emitMarshalField(FieldDescriptorProto $f, array &$lines): void
    {
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
            $lines[] = '                ' . $this->emitWriteItem('$k', $keyF);
            $lines[] = '                ' . $this->emitWriteItem('$v', $valF);
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
            $lines[] = '                ' . $this->emitWriteItem('$item', $f);
            $lines[] = '            }';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
            return;
        }

        if ($type === Type::TYPE_MESSAGE) {
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::SUB_MSG));
            $lines[] = "        if (\$this->{$prop} !== null) {";
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = "            \$this->{$prop}->marshalTo(\$enc, \$deterministic);";
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
            return;
        }

        if ($type === Type::TYPE_BOOL) {
            $trueTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_TRUE));
            $lines[] = "        if (\$this->{$prop}) {";
            $lines[] = "            \$enc->buf .= {$trueTag};";
            $lines[] = '        }';
            return;
        }

        $wt = $this->protoTypeToWireType($type);
        $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, $wt));

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
     * @param list<string> $lines
     */
    private function emitDecodeFieldCase(FieldDescriptorProto $f, array &$lines): void
    {
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
            $lines[] = '                        $k = ' . $this->emitReadItem('$mapDec', $keyF) . ';';
            $lines[] = "                        \$this->{$prop}[\$k] = " . $this->emitReadItem('$mapDec', $valF) . ';';
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
            $lines[] = "                        \$this->{$prop}[] = " . $this->emitReadItem('$arrDec', $f) . ';';
            $lines[] = '                    }';
            $lines[] = '                    $arrDec->assertAtEnd(\'WireArray\');';
            $lines[] = '                    break;';
            return;
        }

        if ($type === Type::TYPE_BOOL) {
            $lines[] = '                    if ($wireType === WireType::BOOL_FALSE) {';
            $lines[] = "                        \$this->{$prop} = false;";
            $lines[] = '                    } elseif ($wireType === WireType::BOOL_TRUE) {';
            $lines[] = "                        \$this->{$prop} = true;";
            $lines[] = '                    } else {';
            $lines[] = '                        $dec->skipUnknown($wireType);';
            $lines[] = '                    }';
            $lines[] = '                    break;';
            return;
        }

        $wt = $this->protoTypeToWireType($type);
        $lines[] = "                    if (\$wireType !== {$wt}) { \$dec->skipUnknown(\$wireType); break; }";

        if ($type === Type::TYPE_MESSAGE) {
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

        $lines[] = "                    \$this->{$prop} = " . $this->emitReadItem('$dec', $f) . ';';
        $lines[] = '                    break;';
    }

    /**
     * @param list<string> $lines
     */
    private function emitOpaqueAccessors(FieldDescriptorProto $f, array &$lines): void
    {
        $num = (int) $f->getNumber();
        $pascal = $this->pascalCase((string) $f->getName());
        $type = (int) $f->getType();
        [$phpType, , $doc] = $this->phpPropSpec($f);

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
            $lines[] = "        \$ranges = Opaque::locateAllLengthPrefixed(\$this->raw, 0, \\strlen(\$this->raw), {$num}, WireType::MAP, 3);";
            $lines[] = '        $out = [];';
            $lines[] = '        foreach ($ranges as [$s, $e]) {';
            $lines[] = '            $dec = new Decoder($this->raw, $s, $e, 1);';
            $lines[] = '            $kt = \ord($this->raw[$s]);';
            $lines[] = '            $vt = \ord($this->raw[$s + 1]);';
            $lines[] = "            if (\$kt !== {$kWt} || \$vt !== {$vWt}) { throw new \\OmniBuf\\Exception\\DecodeException('omnibuf: WireMap header mismatch'); }";
            $lines[] = '            $dec->pos = $s + 2;';
            $lines[] = '            $count = $dec->readVarint();';
            $lines[] = '            if ($count === 0) { $dec->assertAtEnd(\'WireMap\'); continue; }';
            $lines[] = '            for ($i = 0; $i < $count; $i++) {';
            $lines[] = '                $k = ' . $this->emitReadItem('$dec', $keyF) . ';';
            $lines[] = '                $out[$k] = ' . $this->emitReadItem('$dec', $valF) . ';';
            $lines[] = '            }';
            $lines[] = '            $dec->assertAtEnd(\'WireMap\');';
            $lines[] = '        }';
            $lines[] = '        return $out;';
        } elseif ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $lines[] = "        \$ranges = Opaque::locateAllLengthPrefixed(\$this->raw, 0, \\strlen(\$this->raw), {$num}, WireType::ARRAY, 2);";
            $lines[] = '        $out = [];';
            $lines[] = '        foreach ($ranges as [$s, $e]) {';
            $lines[] = '            $dec = new Decoder($this->raw, $s, $e, 1);';
            $lines[] = '            $et = \ord($this->raw[$s]);';
            $lines[] = "            if (\$et !== {$eWt}) { throw new \\OmniBuf\\Exception\\DecodeException('omnibuf: WireArray element type mismatch'); }";
            $lines[] = '            $dec->pos = $s + 1;';
            $lines[] = '            $count = $dec->readVarint();';
            $lines[] = '            if ($count === 0) { $dec->assertAtEnd(\'WireArray\'); continue; }';
            $lines[] = '            for ($i = 0; $i < $count; $i++) {';
            $lines[] = '                $out[] = ' . $this->emitReadItem('$dec', $f) . ';';
            $lines[] = '            }';
            $lines[] = '            $dec->assertAtEnd(\'WireArray\');';
            $lines[] = '        }';
            $lines[] = '        return $out;';
        } elseif ($type === Type::TYPE_MESSAGE) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
            $lines[] = '        if ($slice === null) { return null; }';
            $lines[] = "        return {$subClass}::unmarshalOmnibuf(\$slice);";
        } else {
            $helper = match ($type) {
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
            $lines[] = "        return Opaque::{$helper}(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
        }
        $lines[] = '    }';
        $lines[] = '';

        // Extra lazy opaque<SubMsg>() view for message fields
        if ($type === Type::TYPE_MESSAGE && (int) $f->getLabel() !== Label::LABEL_REPEATED && !$this->isMapField($f)) {
            $subClass = $this->messages[(string) $f->getTypeName()]['phpClass'];
            $lastSlash = strrpos($subClass, '\\');
            $subOpaque = $lastSlash !== false
                ? substr($subClass, 0, $lastSlash + 1) . 'Opaque' . substr($subClass, $lastSlash + 1)
                : 'Opaque' . $subClass;
            $lines[] = "    public function opaque{$pascal}(): ?{$subOpaque}";
            $lines[] = '    {';
            $lines[] = "        \$slice = Opaque::getSubMessageSlice(\$this->raw, 0, \\strlen(\$this->raw), {$num});";
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
        $lines[] = "        \$this->dirtyValues[{$num}] = \$value;";
        $lines[] = '        $enc = new Encoder();';
        if ($this->isMapField($f)) {
            [$keyF, $valF] = $this->mapKeyValFields($f);
            $kWt = $this->protoTypeToWireType((int) $keyF->getType());
            $vWt = $this->protoTypeToWireType((int) $valF->getType());
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::MAP));
            $lines[] = '        if ($value !== []) {';
            $lines[] = '            $entries = $value;';
            $lines[] = $this->mapSortExpr('$entries', $kWt, '            ');
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = '            $enc->buf .= ' . $this->phpStringLiteral(\chr($kWt) . \chr($vWt)) . ';';
            $lines[] = '            $enc->writeVarint(\count($entries));';
            $lines[] = '            foreach ($entries as $k => $v) {';
            $lines[] = '                ' . $this->emitWriteItem('$k', $keyF);
            $lines[] = '                ' . $this->emitWriteItem('$v', $valF);
            $lines[] = '            }';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
        } elseif ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eWt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::ARRAY));
            $lines[] = '        if ($value !== []) {';
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = '            $enc->buf .= ' . $this->phpStringLiteral(\chr($eWt)) . ';';
            $lines[] = '            $enc->writeVarint(\count($value));';
            $lines[] = '            foreach ($value as $item) {';
            $lines[] = '                ' . $this->emitWriteItem('$item', $f);
            $lines[] = '            }';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
        } elseif ($type === Type::TYPE_MESSAGE) {
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, WireType::SUB_MSG));
            $lines[] = '        if ($value !== null) {';
            $lines[] = "            \$enc->buf .= {$tagBytes};";
            $lines[] = '            $enc->beginContainer();';
            $lines[] = '            $value->marshalTo($enc, true);';
            $lines[] = '            $enc->endContainer();';
            $lines[] = '        }';
        } elseif ($type === Type::TYPE_BOOL) {
            $trueTag = $this->phpStringLiteral(Wire::encodeTag($num, WireType::BOOL_TRUE));
            $lines[] = "        if (\$value) { \$enc->buf .= {$trueTag}; }";
        } else {
            $wt = $this->protoTypeToWireType($type);
            $tagBytes = $this->phpStringLiteral(Wire::encodeTag($num, $wt));
            $lines[] = "        \$enc->buf .= {$tagBytes};";
            $lines[] = '        ' . $this->emitWriteItem('$value', $f);
        }
        $lines[] = "        \$this->dirty[{$num}] = \$enc->finish();";
        $lines[] = '    }';
        $lines[] = '';
    }

    private function emitWriteItem(string $expr, FieldDescriptorProto $f): string
    {
        return match ((int) $f->getType()) {
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
            Type::TYPE_MESSAGE => "\$enc->beginContainer(); {$expr}->marshalTo(\$enc, \$deterministic ?? true); \$enc->endContainer();",
            default => '',
        };
    }

    private function emitReadItem(string $decVar, FieldDescriptorProto $f): string
    {
        $type = (int) $f->getType();
        if ($type === Type::TYPE_MESSAGE) {
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
    private function phpPropSpec(FieldDescriptorProto $f): array
    {
        if ($this->isMapField($f)) {
            [$kF, $vF] = $this->mapKeyValFields($f);
            $kType = $this->scalarPhpType((int) $kF->getType());
            $vType = (int) $vF->getType() === Type::TYPE_MESSAGE
                ? $this->messages[(string) $vF->getTypeName()]['phpClass']
                : $this->scalarPhpType((int) $vF->getType());
            return ['array', '[]', "array<{$kType}, {$vType}>"];
        }
        if ((int) $f->getLabel() === Label::LABEL_REPEATED) {
            $eType = (int) $f->getType() === Type::TYPE_MESSAGE
                ? $this->messages[(string) $f->getTypeName()]['phpClass']
                : $this->scalarPhpType((int) $f->getType());
            return ['array', '[]', "list<{$eType}>"];
        }
        $type = (int) $f->getType();
        if ($type === Type::TYPE_MESSAGE) {
            $cls = $this->messages[(string) $f->getTypeName()]['phpClass'];
            return ["?{$cls}", 'null', null];
        }
        $phpType = $this->scalarPhpType($type);
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

    private function childMessageClass(FieldDescriptorProto $f): ?string
    {
        if ($this->isMapField($f)) {
            [, $valF] = $this->mapKeyValFields($f);
            if ((int) $valF->getType() === Type::TYPE_MESSAGE) {
                return $this->messages[(string) $valF->getTypeName()]['phpClass'];
            }
            return null;
        }
        if ((int) $f->getType() === Type::TYPE_MESSAGE) {
            return $this->messages[(string) $f->getTypeName()]['phpClass'];
        }
        return null;
    }

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
            Type::TYPE_MESSAGE => WireType::SUB_MSG,
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
                $pos += 8;
            } elseif ($wireType === 5) {
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
            return $out;
        }
        // Field 2: supported_features = 1 (FEATURE_PROTO3_OPTIONAL)
        $out .= "\x10\x01";
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
        return $out . \chr($v);
    }

    private function writePbString(int $fieldNum, string $data): string
    {
        return $this->writePbVarint(($fieldNum << 3) | 2) . $this->writePbVarint(\strlen($data)) . $data;
    }
}
