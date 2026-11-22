<?php

/**
 * @param list<array{0: int, 1: int, 2: string, 3?: int}> $fields
 */
function omnibuf_native_register_schema(string $className, array $fields): int {}

function omnibuf_native_encode(int $schemaId, object $obj): string {}

function omnibuf_native_decode(int $schemaId, string $bytes): object {}

function omnibuf_native_opaque_get_string(string $raw, int $start, int $end, int $fieldNum): string {}

function omnibuf_native_opaque_get_uint64(string $raw, int $start, int $end, int $fieldNum): int {}

/**
 * @return array{0: int, 1: int}|null
 */
function omnibuf_native_opaque_get_submsg_range(string $raw, int $start, int $end, int $fieldNum): ?array {}

/**
 * @param array<int, string> $dirty
 */
function omnibuf_native_opaque_splice(string $prefix, string $raw, array $dirty): string {}

function omnibuf_native_decode_blind_json(string $raw): string {}
