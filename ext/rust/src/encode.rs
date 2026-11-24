use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendObject, Zval};
use omnibuf_core::wire::{
    MAX_NESTING_DEPTH, WIRE_BOOL_FALSE, WIRE_BOOL_TRUE, append_varint, backpatch_container_len,
    encode_zigzag,
};

use crate::encode_exception;
use crate::schema::{ElemKind, FieldKind, ScalarKind, SchemaDef, with_schemas};

fn zval_to_i64(val: &Zval) -> PhpResult<i64> {
    let v = val.dereference();
    if let Some(n) = v.long() {
        Ok(n)
    } else if let Some(b) = v.bool() {
        Ok(i64::from(b))
    } else if let Some(f) = v.double() {
        Ok(f as i64)
    } else {
        Err(encode_exception("omnibuf: expected integer value"))
    }
}

fn zval_to_f64(val: &Zval) -> PhpResult<f64> {
    let v = val.dereference();
    if let Some(f) = v.double() {
        Ok(f)
    } else if let Some(n) = v.long() {
        Ok(n as f64)
    } else {
        Err(encode_exception("omnibuf: expected float value"))
    }
}

fn is_zero_scalar(kind: ScalarKind, val: &Zval) -> bool {
    let v = val.dereference();
    match kind {
        ScalarKind::Bool => !v.bool().unwrap_or(false),
        ScalarKind::Uint32 | ScalarKind::Fixed32 => (v.long().unwrap_or(0) & 0xFFFF_FFFF) == 0,
        ScalarKind::Sint32 | ScalarKind::Sfixed32 => (v.long().unwrap_or(0) as i32) == 0,
        ScalarKind::Uint64 | ScalarKind::Sint64 | ScalarKind::Fixed64 | ScalarKind::Sfixed64 => {
            v.long().unwrap_or(0) == 0
        }
        ScalarKind::Float32 => {
            let f = v.double().unwrap_or(0.0) as f32;
            f.to_bits() == 0
        }
        ScalarKind::Float64 => {
            let f = v.double().unwrap_or(0.0);
            f.to_bits() == 0
        }
        ScalarKind::String | ScalarKind::Bytes => {
            v.binary_slice::<u8>().map(|b| b.is_empty()).unwrap_or(true)
        }
    }
}

fn write_scalar_value(buf: &mut Vec<u8>, kind: ScalarKind, val: &Zval) -> PhpResult<()> {
    let v = val.dereference();
    match kind {
        ScalarKind::Uint32 => {
            let n = (zval_to_i64(v)? as u64) & 0xFFFF_FFFF;
            append_varint(buf, n);
        }
        ScalarKind::Uint64 => {
            let n = zval_to_i64(v)? as u64;
            append_varint(buf, n);
        }
        ScalarKind::Sint32 => {
            let n = i64::from(zval_to_i64(v)? as i32);
            append_varint(buf, encode_zigzag(n));
        }
        ScalarKind::Sint64 => {
            let n = zval_to_i64(v)?;
            append_varint(buf, encode_zigzag(n));
        }
        ScalarKind::Bool => {
            buf.push(u8::from(v.bool().unwrap_or(false)));
        }
        ScalarKind::Fixed32 => {
            let n = zval_to_i64(v)? as u32;
            buf.extend_from_slice(&n.to_le_bytes());
        }
        ScalarKind::Fixed64 => {
            let n = zval_to_i64(v)? as u64;
            buf.extend_from_slice(&n.to_le_bytes());
        }
        ScalarKind::Sfixed32 => {
            let n = zval_to_i64(v)? as i32;
            buf.extend_from_slice(&n.to_le_bytes());
        }
        ScalarKind::Sfixed64 => {
            let n = zval_to_i64(v)?;
            buf.extend_from_slice(&n.to_le_bytes());
        }
        ScalarKind::Float32 => {
            let f = zval_to_f64(v)? as f32;
            buf.extend_from_slice(&f.to_le_bytes());
        }
        ScalarKind::Float64 => {
            let f = zval_to_f64(v)?;
            buf.extend_from_slice(&f.to_le_bytes());
        }
        ScalarKind::String => {
            let bytes = v
                .binary_slice()
                .ok_or_else(|| encode_exception("omnibuf: expected string value"))?;
            if std::str::from_utf8(bytes).is_err() {
                return Err(encode_exception("omnibuf: string contains invalid UTF-8"));
            }
            append_varint(buf, bytes.len() as u64);
            buf.extend_from_slice(bytes);
        }
        ScalarKind::Bytes => {
            let bytes = v
                .binary_slice()
                .ok_or_else(|| encode_exception("omnibuf: expected bytes string value"))?;
            append_varint(buf, bytes.len() as u64);
            buf.extend_from_slice(bytes);
        }
    }
    Ok(())
}

fn write_container<F>(buf: &mut Vec<u8>, body_fn: F) -> PhpResult<()>
where
    F: FnOnce(&mut Vec<u8>) -> PhpResult<()>,
{
    let len_pos = buf.len();
    buf.extend_from_slice(&[0u8, 0u8]);
    body_fn(buf)?;
    backpatch_container_len(buf, len_pos);
    Ok(())
}

fn write_elem(
    buf: &mut Vec<u8>,
    schemas: &[SchemaDef],
    elem: ElemKind,
    val: &Zval,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    match elem {
        ElemKind::Scalar(s) => write_scalar_value(buf, s, val),
        ElemKind::SubMsg(sub_id) => {
            let obj = val
                .dereference()
                .object()
                .ok_or_else(|| encode_exception("omnibuf: expected submessage object"))?;
            write_container(buf, |b| {
                encode_object(b, schemas, sub_id, obj, deterministic, depth + 1)
            })
        }
    }
}

fn write_map_key(buf: &mut Vec<u8>, kind: ScalarKind, key: &ArrayKey<'_>) -> PhpResult<()> {
    match kind {
        ScalarKind::String => {
            let s: Vec<u8> = match key {
                ArrayKey::Str(s) => s.as_bytes().to_vec(),
                ArrayKey::String(s) => s.as_bytes().to_vec(),
                ArrayKey::ZendString(s) => s.as_bytes().to_vec(),
                ArrayKey::Long(n) => n.to_string().into_bytes(),
            };
            if std::str::from_utf8(&s).is_err() {
                return Err(encode_exception("omnibuf: map key contains invalid UTF-8"));
            }
            append_varint(buf, s.len() as u64);
            buf.extend_from_slice(&s);
        }
        ScalarKind::Bool => {
            let n = match key {
                ArrayKey::Long(n) => *n != 0,
                ArrayKey::Str(s) => !s.is_empty() && *s != "0",
                ArrayKey::String(s) => !s.is_empty() && s != "0",
                ArrayKey::ZendString(s) => {
                    let b = s.as_bytes();
                    !b.is_empty() && b != b"0"
                }
            };
            buf.push(u8::from(n));
        }
        _ => {
            let n = match key {
                ArrayKey::Long(n) => *n,
                ArrayKey::Str(s) => s
                    .parse::<i64>()
                    .map_err(|_| encode_exception("omnibuf: invalid integer map key"))?,
                ArrayKey::String(s) => s
                    .parse::<i64>()
                    .map_err(|_| encode_exception("omnibuf: invalid integer map key"))?,
                ArrayKey::ZendString(s) => s
                    .as_str()
                    .ok()
                    .and_then(|st| st.parse::<i64>().ok())
                    .ok_or_else(|| encode_exception("omnibuf: invalid integer map key"))?,
            };
            match kind {
                ScalarKind::Uint32 => append_varint(buf, (n as u64) & 0xFFFF_FFFF),
                ScalarKind::Uint64 => append_varint(buf, n as u64),
                ScalarKind::Sint32 => append_varint(buf, encode_zigzag(i64::from(n as i32))),
                ScalarKind::Sint64 => append_varint(buf, encode_zigzag(n)),
                ScalarKind::Fixed32 => buf.extend_from_slice(&(n as u32).to_le_bytes()),
                ScalarKind::Fixed64 => buf.extend_from_slice(&(n as u64).to_le_bytes()),
                ScalarKind::Sfixed32 => buf.extend_from_slice(&(n as i32).to_le_bytes()),
                ScalarKind::Sfixed64 => buf.extend_from_slice(&n.to_le_bytes()),
                _ => unreachable!(),
            }
        }
    }
    Ok(())
}

fn encode_map(
    buf: &mut Vec<u8>,
    schemas: &[SchemaDef],
    ht: &ZendHashTable,
    key_kind: ScalarKind,
    val_kind: ElemKind,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    buf.push(key_kind.wire_type());
    buf.push(val_kind.wire_type());
    append_varint(buf, ht.len() as u64);

    if deterministic && ht.len() > 1 {
        let mut entries: Vec<(ArrayKey<'_>, &Zval)> = ht.iter().collect();
        match key_kind {
            ScalarKind::String => entries.sort_by(|(a, _), (b, _)| {
                let sa = a.to_string();
                let sb = b.to_string();
                sa.as_bytes().cmp(sb.as_bytes())
            }),
            ScalarKind::Uint32 | ScalarKind::Uint64 | ScalarKind::Fixed32 | ScalarKind::Fixed64 => {
                entries.sort_by_key(|(k, _)| match k {
                    ArrayKey::Long(n) => *n as u64,
                    _ => k.to_string().parse::<i64>().unwrap_or(0) as u64,
                });
            }
            _ => {
                entries.sort_by_key(|(k, _)| match k {
                    ArrayKey::Long(n) => *n,
                    _ => k.to_string().parse::<i64>().unwrap_or(0),
                });
            }
        }
        for (k, v) in entries {
            write_map_key(buf, key_kind, &k)?;
            write_elem(buf, schemas, val_kind, v, deterministic, depth)?;
        }
    } else {
        for (k, v) in ht.iter() {
            write_map_key(buf, key_kind, &k)?;
            write_elem(buf, schemas, val_kind, v, deterministic, depth)?;
        }
    }
    Ok(())
}

fn encode_object(
    buf: &mut Vec<u8>,
    schemas: &[SchemaDef],
    schema_id: usize,
    obj: &ZendObject,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    if depth > MAX_NESTING_DEPTH {
        return Err(encode_exception("omnibuf: encode nesting depth exceeded"));
    }
    let schema = schemas
        .get(schema_id)
        .ok_or_else(|| encode_exception("omnibuf: invalid schema id"))?;

    let actual_class = obj.get_class_name().unwrap_or_default();
    if actual_class != schema.class_name {
        return Err(encode_exception(&format!(
            "omnibuf: expected instance of {}, got {}",
            schema.class_name, actual_class
        )));
    }

    for f in &schema.fields {
        let val = match obj.get_property::<&Zval>(&f.prop) {
            Ok(v) => v.dereference(),
            Err(_) => continue,
        };

        if val.is_null() {
            continue;
        }

        match f.kind {
            FieldKind::SubMsg(sub_id) => {
                let sub_obj = val
                    .object()
                    .ok_or_else(|| encode_exception("omnibuf: expected submessage object"))?;
                buf.extend_from_slice(&f.tag_bytes[..usize::from(f.tag_len)]);
                write_container(buf, |b| {
                    encode_object(b, schemas, sub_id, sub_obj, deterministic, depth + 1)
                })?;
            }
            FieldKind::Array(elem_kind) => {
                let Some(ht) = val.array() else {
                    continue;
                };
                if ht.is_empty() && f.omit_empty {
                    continue;
                }
                buf.extend_from_slice(&f.tag_bytes[..usize::from(f.tag_len)]);
                write_container(buf, |b| {
                    b.push(elem_kind.wire_type());
                    append_varint(b, ht.len() as u64);
                    for (_, item) in ht.iter() {
                        write_elem(b, schemas, elem_kind, item, deterministic, depth)?;
                    }
                    Ok(())
                })?;
            }
            FieldKind::Map { key, val: val_kind } => {
                let Some(ht) = val.array() else {
                    continue;
                };
                if ht.is_empty() && f.omit_empty {
                    continue;
                }
                buf.extend_from_slice(&f.tag_bytes[..usize::from(f.tag_len)]);
                write_container(buf, |b| {
                    encode_map(b, schemas, ht, key, val_kind, deterministic, depth)
                })?;
            }
            FieldKind::Scalar(ScalarKind::Bool) => {
                let b = val.bool().unwrap_or(false);
                if f.omit_empty && !f.nullable && !b {
                    continue;
                }
                let wt = if b { WIRE_BOOL_TRUE } else { WIRE_BOOL_FALSE };
                let tag = (u64::from(f.number) << 4) | u64::from(wt);
                append_varint(buf, tag);
            }
            FieldKind::Scalar(scalar) => {
                if f.omit_empty && !f.nullable && is_zero_scalar(scalar, val) {
                    continue;
                }
                buf.extend_from_slice(&f.tag_bytes[..usize::from(f.tag_len)]);
                write_scalar_value(buf, scalar, val)?;
            }
        }
    }
    Ok(())
}

pub fn encode_with_schema(
    schema_id: i64,
    obj: &ZendObject,
    deterministic: bool,
) -> PhpResult<Vec<u8>> {
    if schema_id < 0 {
        return Err(encode_exception("omnibuf: invalid schema id"));
    }
    with_schemas(|schemas| {
        let mut buf = Vec::with_capacity(512);
        encode_object(&mut buf, schemas, schema_id as usize, obj, deterministic, 0)?;
        Ok(buf)
    })
}
