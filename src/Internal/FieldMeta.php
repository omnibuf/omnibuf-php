<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

/**
 * Cached reflection metadata for a single #[OmniBufField]-annotated property.
 *
 * @internal
 */
final readonly class FieldMeta
{
    /**
     * @param ?class-string $messageClass
     */
    public function __construct(
        public int $number,
        public string $prop,
        public string $wireName,
        public string $kind,
        public int $wireType,
        public string $elemKind,
        public int $elemWireType,
        public string $keyKind,
        public int $keyWireType,
        public string $valKind,
        public int $valWireType,
        public ?string $messageClass,
        public bool $omitEmpty,
        public bool $nullable,
        public mixed $default,
    ) {}
}
