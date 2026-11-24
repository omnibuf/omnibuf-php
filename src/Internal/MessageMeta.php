<?php

declare(strict_types=1);

namespace OmniBuf\Internal;

/**
 * Cached reflection metadata for an #[OmniBufMessage]-annotated class.
 *
 * @internal
 */
final readonly class MessageMeta
{
    /**
     * @param list<FieldMeta> $fields
     * @param array<int, FieldMeta> $byNumber
     */
    public function __construct(
        public string $name,
        public array $fields,
        public array $byNumber,
    ) {}
}
