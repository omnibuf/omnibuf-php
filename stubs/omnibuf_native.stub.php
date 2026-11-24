<?php

/**
 * @param list<array{
 *   number: int,
 *   prop: string,
 *   kind: string,
 *   omitEmpty?: bool,
 *   nullable?: bool,
 *   elemKind?: string,
 *   keyKind?: string,
 *   valKind?: string,
 *   subSchemaId?: int
 * }> $fields
 */
function omnibuf_native_register_schema(string $className, array $fields): int {}

function omnibuf_native_encode(int $schemaId, object $obj, bool $deterministic = false): string {}

function omnibuf_native_decode(
    int $schemaId,
    string $raw,
    int $maxDepth = 64,
    int $maxElements = 1_000_000,
): object {}

/**
 * @return array{0: int, 1: int, 2: int}|null
 */
function omnibuf_native_opaque_locate_last(
    string $raw,
    int $start,
    int $end,
    int $fieldNum,
    int $expectedWireType,
): ?array {}

function omnibuf_native_opaque_get_string(string $raw, int $start, int $end, int $fieldNum): string {}

function omnibuf_native_opaque_get_uint64(string $raw, int $start, int $end, int $fieldNum): int {}

function omnibuf_native_opaque_get_submsg_slice(string $raw, int $start, int $end, int $fieldNum): ?string {}

/**
 * @param array<int, string> $dirty
 */
function omnibuf_native_opaque_splice(string $prefix, string $raw, array $dirty): string {}

function omnibuf_native_decode_blind_json(string $raw): string {}
