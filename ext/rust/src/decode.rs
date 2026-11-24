use ext_php_rs::binary::Binary;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::ClassEntry;
use omnibuf_core::field0::validate_field0;
use omnibuf_core::wire::{
    WIRE_BOOL_FALSE, WIRE_BOOL_TRUE, decode_varint, decode_zigzag, read_tag_at, skip_value_opt,
};

use crate::decode_exception;
use crate::schema::{ElemKind, FieldKind, ScalarKind, SchemaDef, with_schemas};

struct DecodeLimits {
    max_depth: usize,
    max_elements: usize,
    elements_used: usize,
}

impl DecodeLimits {
    fn consume(&mut self, count: usize) -> PhpResult<()> {
        self.elements_used = self
            .elements_used
            .checked_add(count)
            .ok_or_else(|| decode_exception("omnibuf: element budget exceeded"))?;
        if self.elements_used > self.max_elements {
            return Err(decode_exception("omnibuf: element budget exceeded"));
        }
        Ok(())
    }
}

fn read_varint_at(raw: &[u8], pos: &mut usize) -> PhpResult<u64> {
    let (v, n) = decode_varint(&raw[*pos..])
        .ok_or_else(|| decode_exception("omnibuf: truncated or invalid varint"))?;
    *pos += n;
    Ok(v)
}

fn read_bytes_slice<'a>(raw: &'a [u8], pos: &mut usize, len: usize) -> PhpResult<&'a [u8]> {
    let end = pos
        .checked_add(len)
        .ok_or_else(|| decode_exception("omnibuf: length overflow"))?;
    if end > raw.len() {
        return Err(decode_exception("omnibuf: unexpected end of buffer"));
    }
    let slice = &raw[*pos..end];
    *pos = end;
    Ok(slice)
}

fn read_scalar_zval(raw: &[u8], pos: &mut usize, kind: ScalarKind) -> PhpResult<Zval> {
    let zval = match kind {
        ScalarKind::Uint32 => {
            let v = (read_varint_at(raw, pos)? & 0xFFFF_FFFF) as i64;
            v.into_zval(false)
        }
        ScalarKind::Uint64 => {
            let v = read_varint_at(raw, pos)? as i64;
            v.into_zval(false)
        }
        ScalarKind::Sint32 => {
            let v = i64::from(decode_zigzag(read_varint_at(raw, pos)?) as i32);
            v.into_zval(false)
        }
        ScalarKind::Sint64 => {
            let v = decode_zigzag(read_varint_at(raw, pos)?);
            v.into_zval(false)
        }
        ScalarKind::Bool => {
            let b = read_bytes_slice(raw, pos, 1)?[0];
            if b > 1 {
                return Err(decode_exception("omnibuf: invalid bool byte"));
            }
            (b != 0).into_zval(false)
        }
        ScalarKind::Fixed32 => {
            let b = read_bytes_slice(raw, pos, 4)?;
            let v = i64::from(u32::from_le_bytes([b[0], b[1], b[2], b[3]]));
            v.into_zval(false)
        }
        ScalarKind::Fixed64 => {
            let b = read_bytes_slice(raw, pos, 8)?;
            let v = u64::from_le_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]]) as i64;
            v.into_zval(false)
        }
        ScalarKind::Sfixed32 => {
            let b = read_bytes_slice(raw, pos, 4)?;
            let v = i64::from(i32::from_le_bytes([b[0], b[1], b[2], b[3]]));
            v.into_zval(false)
        }
        ScalarKind::Sfixed64 => {
            let b = read_bytes_slice(raw, pos, 8)?;
            let v = i64::from_le_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]]);
            v.into_zval(false)
        }
        ScalarKind::Float32 => {
            let b = read_bytes_slice(raw, pos, 4)?;
            let v = f64::from(f32::from_le_bytes([b[0], b[1], b[2], b[3]]));
            v.into_zval(false)
        }
        ScalarKind::Float64 => {
            let b = read_bytes_slice(raw, pos, 8)?;
            let v = f64::from_le_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]]);
            v.into_zval(false)
        }
        ScalarKind::String => {
            let len = read_varint_at(raw, pos)? as usize;
            let bytes = read_bytes_slice(raw, pos, len)?;
            let s = std::str::from_utf8(bytes)
                .map_err(|_| decode_exception("omnibuf: invalid UTF-8 string"))?;
            s.into_zval(false)
        }
        ScalarKind::Bytes => {
            let len = read_varint_at(raw, pos)? as usize;
            let bytes = read_bytes_slice(raw, pos, len)?;
            Binary::from(bytes.to_vec()).into_zval(false)
        }
    };
    zval.map_err(|_| decode_exception("omnibuf: failed to construct PHP scalar value"))
}

fn read_map_key<'a>(raw: &'a [u8], pos: &mut usize, kind: ScalarKind) -> PhpResult<ArrayKey<'a>> {
    match kind {
        ScalarKind::String => {
            let len = read_varint_at(raw, pos)? as usize;
            let bytes = read_bytes_slice(raw, pos, len)?;
            let s = std::str::from_utf8(bytes)
                .map_err(|_| decode_exception("omnibuf: invalid UTF-8 map key"))?;
            Ok(ArrayKey::Str(s))
        }
        ScalarKind::Bool => {
            let b = read_bytes_slice(raw, pos, 1)?[0];
            if b > 1 {
                return Err(decode_exception("omnibuf: invalid bool map key"));
            }
            Ok(ArrayKey::Long(i64::from(b)))
        }
        ScalarKind::Uint32 => Ok(ArrayKey::Long(
            (read_varint_at(raw, pos)? & 0xFFFF_FFFF) as i64,
        )),
        ScalarKind::Uint64 => Ok(ArrayKey::Long(read_varint_at(raw, pos)? as i64)),
        ScalarKind::Sint32 => Ok(ArrayKey::Long(i64::from(
            decode_zigzag(read_varint_at(raw, pos)?) as i32,
        ))),
        ScalarKind::Sint64 => Ok(ArrayKey::Long(decode_zigzag(read_varint_at(raw, pos)?))),
        ScalarKind::Fixed32 => {
            let b = read_bytes_slice(raw, pos, 4)?;
            Ok(ArrayKey::Long(i64::from(u32::from_le_bytes([
                b[0], b[1], b[2], b[3],
            ]))))
        }
        ScalarKind::Fixed64 => {
            let b = read_bytes_slice(raw, pos, 8)?;
            Ok(ArrayKey::Long(
                u64::from_le_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]]) as i64,
            ))
        }
        ScalarKind::Sfixed32 => {
            let b = read_bytes_slice(raw, pos, 4)?;
            Ok(ArrayKey::Long(i64::from(i32::from_le_bytes([
                b[0], b[1], b[2], b[3],
            ]))))
        }
        ScalarKind::Sfixed64 => {
            let b = read_bytes_slice(raw, pos, 8)?;
            Ok(ArrayKey::Long(i64::from_le_bytes([
                b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7],
            ])))
        }
        _ => Err(decode_exception("omnibuf: unsupported map key kind")),
    }
}

fn read_elem_zval(
    raw: &[u8],
    pos: &mut usize,
    schemas: &[SchemaDef],
    elem: ElemKind,
    depth: usize,
    limits: &mut DecodeLimits,
) -> PhpResult<Zval> {
    match elem {
        ElemKind::Scalar(s) => read_scalar_zval(raw, pos, s),
        ElemKind::SubMsg(sub_id) => {
            let len = read_varint_at(raw, pos)? as usize;
            let body = read_bytes_slice(raw, pos, len)?;
            let obj = decode_new_object(body, schemas, sub_id, depth + 1, limits)?;
            obj.into_zval(false)
                .map_err(|_| decode_exception("omnibuf: failed to convert submessage object"))
        }
    }
}

fn decode_into_object(
    raw: &[u8],
    schemas: &[SchemaDef],
    schema_id: usize,
    obj: &mut ZendObject,
    depth: usize,
    limits: &mut DecodeLimits,
) -> PhpResult<()> {
    if depth > limits.max_depth {
        return Err(decode_exception("omnibuf: nesting depth limit exceeded"));
    }
    let schema = schemas
        .get(schema_id)
        .ok_or_else(|| decode_exception("omnibuf: invalid schema id"))?;

    let mut pos = 0usize;
    while pos < raw.len() {
        let Some((field_num, wt, next)) = read_tag_at(raw, pos) else {
            return Err(decode_exception("omnibuf: truncated or invalid tag"));
        };
        pos = next;

        if field_num == 0 {
            if wt != omnibuf_core::wire::WIRE_SUB_MSG {
                return Err(decode_exception("omnibuf: Field #0 must be WireSubMsg"));
            }
            pos = validate_field0(raw, pos, depth)
                .map_err(|_| decode_exception("omnibuf: malformed Field #0"))?;
            continue;
        }

        let Some(fidx) = schema.field_index(field_num) else {
            pos = skip_value_opt(raw, pos, wt)
                .ok_or_else(|| decode_exception("omnibuf: truncated unknown field"))?;
            continue;
        };

        let f = &schema.fields[fidx];

        if let FieldKind::Scalar(ScalarKind::Bool) = f.kind {
            if wt == WIRE_BOOL_FALSE || wt == WIRE_BOOL_TRUE {
                obj.set_property(&f.prop, wt == WIRE_BOOL_TRUE)
                    .map_err(|_| decode_exception("omnibuf: failed to set bool property"))?;
            } else {
                pos = skip_value_opt(raw, pos, wt)
                    .ok_or_else(|| decode_exception("omnibuf: truncated mismatched field"))?;
            }
            continue;
        }

        if wt != f.kind.wire_type() {
            pos = skip_value_opt(raw, pos, wt)
                .ok_or_else(|| decode_exception("omnibuf: truncated mismatched field"))?;
            continue;
        }

        match f.kind {
            FieldKind::Scalar(scalar) => {
                let zval = read_scalar_zval(raw, &mut pos, scalar)?;
                obj.set_property(&f.prop, zval)
                    .map_err(|_| decode_exception("omnibuf: failed to set scalar property"))?;
            }
            FieldKind::SubMsg(sub_id) => {
                let len = read_varint_at(raw, &mut pos)? as usize;
                let body = read_bytes_slice(raw, &mut pos, len)?;
                if let Ok(existing_zval) = obj.get_property::<&Zval>(&f.prop)
                    && existing_zval.dereference().is_object()
                {
                    let mut cloned = existing_zval.dereference().shallow_clone();
                    if let Some(existing_obj) = cloned.object_mut() {
                        decode_into_object(body, schemas, sub_id, existing_obj, depth + 1, limits)?;
                    }
                } else {
                    let sub_obj = decode_new_object(body, schemas, sub_id, depth + 1, limits)?;
                    obj.set_property(&f.prop, sub_obj).map_err(|_| {
                        decode_exception("omnibuf: failed to set submessage property")
                    })?;
                }
            }
            FieldKind::Array(elem_kind) => {
                let len = read_varint_at(raw, &mut pos)? as usize;
                let body = read_bytes_slice(raw, &mut pos, len)?;
                if body.is_empty() {
                    return Err(decode_exception("omnibuf: truncated WireArray header"));
                }
                let actual_et = body[0];
                if actual_et != elem_kind.wire_type() {
                    return Err(decode_exception("omnibuf: WireArray element type mismatch"));
                }
                let mut bpos = 1usize;
                let count = read_varint_at(body, &mut bpos)? as usize;
                limits.consume(count)?;

                let mut ht = if let Ok(existing) = obj.get_property::<&Zval>(&f.prop)
                    && let Some(existing_ht) = existing.dereference().array()
                    && !existing_ht.is_empty()
                {
                    existing_ht.to_owned()
                } else {
                    ZendHashTable::with_capacity(count.min(1024) as u32)
                };

                for _ in 0..count {
                    let item = read_elem_zval(body, &mut bpos, schemas, elem_kind, depth, limits)?;
                    ht.push(item)
                        .map_err(|_| decode_exception("omnibuf: failed to append array item"))?;
                }
                if bpos != body.len() {
                    return Err(decode_exception("omnibuf: trailing bytes in WireArray"));
                }
                obj.set_property(&f.prop, ht)
                    .map_err(|_| decode_exception("omnibuf: failed to set array property"))?;
            }
            FieldKind::Map { key, val: val_kind } => {
                let len = read_varint_at(raw, &mut pos)? as usize;
                let body = read_bytes_slice(raw, &mut pos, len)?;
                if body.len() < 2 {
                    return Err(decode_exception("omnibuf: truncated WireMap header"));
                }
                if body[0] != key.wire_type() || body[1] != val_kind.wire_type() {
                    return Err(decode_exception("omnibuf: WireMap type mismatch"));
                }
                let mut bpos = 2usize;
                let count = read_varint_at(body, &mut bpos)? as usize;
                limits.consume(count)?;

                let mut ht = if let Ok(existing) = obj.get_property::<&Zval>(&f.prop)
                    && let Some(existing_ht) = existing.dereference().array()
                    && !existing_ht.is_empty()
                {
                    existing_ht.to_owned()
                } else {
                    ZendHashTable::with_capacity(count.min(1024) as u32)
                };

                for _ in 0..count {
                    let k = read_map_key(body, &mut bpos, key)?;
                    let v = read_elem_zval(body, &mut bpos, schemas, val_kind, depth, limits)?;
                    ht.insert(k, v)
                        .map_err(|_| decode_exception("omnibuf: failed to insert map entry"))?;
                }
                if bpos != body.len() {
                    return Err(decode_exception("omnibuf: trailing bytes in WireMap"));
                }
                obj.set_property(&f.prop, ht)
                    .map_err(|_| decode_exception("omnibuf: failed to set map property"))?;
            }
        }
    }

    Ok(())
}

fn decode_new_object(
    raw: &[u8],
    schemas: &[SchemaDef],
    schema_id: usize,
    depth: usize,
    limits: &mut DecodeLimits,
) -> PhpResult<ZBox<ZendObject>> {
    let schema = schemas
        .get(schema_id)
        .ok_or_else(|| decode_exception("omnibuf: invalid schema id"))?;
    let ce = ClassEntry::try_find(&schema.class_name)
        .ok_or_else(|| decode_exception("omnibuf: target PHP class not found"))?;
    let mut obj = ZendObject::new(ce);
    decode_into_object(raw, schemas, schema_id, &mut obj, depth, limits)?;
    Ok(obj)
}

pub fn decode_with_schema(
    schema_id: i64,
    raw: &[u8],
    max_depth: i64,
    max_elements: i64,
) -> PhpResult<ZBox<ZendObject>> {
    if schema_id < 0 || max_depth < 0 || max_elements < 0 {
        return Err(decode_exception("omnibuf: invalid decode arguments"));
    }
    let mut limits = DecodeLimits {
        max_depth: max_depth as usize,
        max_elements: max_elements as usize,
        elements_used: 0,
    };
    with_schemas(|schemas| decode_new_object(raw, schemas, schema_id as usize, 0, &mut limits))
}
