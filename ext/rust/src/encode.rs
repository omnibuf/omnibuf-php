use std::cell::RefCell;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::ffi::{
    IS_ARRAY, IS_DOUBLE, IS_FALSE, IS_LONG, IS_NULL, IS_OBJECT, IS_REFERENCE, IS_STRING, IS_TRUE,
    IS_UNDEF,
};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use omnibuf_core::wire::{MAX_NESTING_DEPTH, encode_zigzag};

use crate::encode_exception;
use crate::schema::{ElemKind, FieldDef, FieldKind, ScalarKind, SchemaDef, with_schemas};

const HASH_FLAG_PACKED: u32 = 1 << 2;
const HASH_FLAG_HAS_EMPTY_IND: u32 = 1 << 5;
const INITIAL_BUF_LEN: usize = 8192;
const MAX_RETAINED_BUF_LEN: usize = 262_144;

thread_local! {
    static ENCODE_BUF: RefCell<Vec<u8>> = RefCell::new(vec![0u8; INITIAL_BUF_LEN]);
}

/// Backward-writing byte buffer that emits container length prefixes directly
/// after encoding children, avoiding `memmove` shifts on containers >16 KiB.
struct RevBuf<'a> {
    buf: &'a mut Vec<u8>,
    pos: usize,
}

impl<'a> RevBuf<'a> {
    #[inline(always)]
    fn new(buf: &'a mut Vec<u8>) -> Self {
        let pos = buf.len();
        Self { buf, pos }
    }

    #[inline(always)]
    fn written(&self) -> usize {
        self.buf.len() - self.pos
    }

    #[inline(always)]
    fn as_slice(&self) -> &[u8] {
        // SAFETY: `self.pos <= self.buf.len()` is maintained by all write methods.
        unsafe { self.buf.get_unchecked(self.pos..) }
    }

    #[inline(always)]
    fn ensure(&mut self, n: usize) {
        if self.pos < n {
            self.grow(n);
        }
    }

    #[cold]
    #[inline(never)]
    fn grow(&mut self, n: usize) {
        let old_len = self.buf.len();
        let used = old_len - self.pos;
        let needed = used.checked_add(n).expect("omnibuf: encode buffer overflow");
        let new_len = needed.next_power_of_two().max(old_len.saturating_mul(2));
        self.buf.resize(new_len, 0);
        let new_pos = new_len - used;
        self.buf.copy_within(self.pos..old_len, new_pos);
        self.pos = new_pos;
    }

    #[inline(always)]
    fn write_u8(&mut self, b: u8) {
        self.ensure(1);
        self.pos -= 1;
        // SAFETY: `ensure(1)` guarantees `self.pos < self.buf.len()`.
        unsafe {
            *self.buf.as_mut_ptr().add(self.pos) = b;
        }
    }

    #[inline(always)]
    fn write_bytes(&mut self, src: &[u8]) {
        let len = src.len();
        if len == 0 {
            return;
        }
        self.ensure(len);
        self.pos -= len;
        // SAFETY: `ensure(len)` guarantees `self.pos + len <= self.buf.len()`.
        unsafe {
            std::ptr::copy_nonoverlapping(src.as_ptr(), self.buf.as_mut_ptr().add(self.pos), len);
        }
    }

    #[inline(always)]
    fn write_u16_le(&mut self, v: u16) {
        self.ensure(2);
        self.pos -= 2;
        unsafe {
            std::ptr::copy_nonoverlapping(
                v.to_le_bytes().as_ptr(),
                self.buf.as_mut_ptr().add(self.pos),
                2,
            );
        }
    }

    #[inline(always)]
    fn write_u32_le(&mut self, v: u32) {
        self.ensure(4);
        self.pos -= 4;
        unsafe {
            std::ptr::copy_nonoverlapping(
                v.to_le_bytes().as_ptr(),
                self.buf.as_mut_ptr().add(self.pos),
                4,
            );
        }
    }

    #[inline(always)]
    fn write_u64_le(&mut self, v: u64) {
        self.ensure(8);
        self.pos -= 8;
        unsafe {
            std::ptr::copy_nonoverlapping(
                v.to_le_bytes().as_ptr(),
                self.buf.as_mut_ptr().add(self.pos),
                8,
            );
        }
    }

    #[inline(always)]
    fn write_varint(&mut self, v: u64) {
        if v < 128 {
            self.write_u8(((v as u8) << 1) | 1);
        } else if v < 16_384 {
            self.write_u16_le(((v as u16) << 2) | 2);
        } else if v < 536_870_912 {
            self.write_u32_le(((v as u32) << 3) | 4);
        } else {
            self.ensure(9);
            self.pos -= 9;
            unsafe {
                let p = self.buf.as_mut_ptr().add(self.pos);
                *p = 0;
                std::ptr::copy_nonoverlapping(v.to_le_bytes().as_ptr(), p.add(1), 8);
            }
        }
    }

    /// Writes a container length prefix matching `backpatch_container_len`
    /// (2-byte varint for `<= 16_383`, 4-byte for `<= 536_870_911`, 9-byte otherwise).
    #[inline(always)]
    fn write_container_len(&mut self, body_len: usize) {
        if body_len <= 16_383 {
            self.write_u16_le(((body_len as u16) << 2) | 2);
        } else {
            self.write_varint(body_len as u64);
        }
    }

    #[inline(always)]
    fn write_tag(&mut self, tag_bytes: &[u8; 9], tag_len: u8) {
        if tag_len == 1 {
            self.write_u8(tag_bytes[0]);
        } else {
            self.write_bytes(&tag_bytes[..usize::from(tag_len)]);
        }
    }
}

#[inline(always)]
fn fast_deref(v: &Zval) -> &Zval {
    // SAFETY: `v.u1.v.type_` is always initialized for a valid Zval.
    let ty = unsafe { v.u1.v.type_ };
    if ty >= IS_REFERENCE as u8 {
        v.dereference()
    } else {
        v
    }
}

#[inline(always)]
fn read_field_zval<'a>(obj: &'a ZendObject, f: &FieldDef) -> Option<&'a Zval> {
    if let Some(offset) = f.prop_offset {
        // SAFETY: `obj.ce == schema.ce` was verified, and `offset` was resolved from
        // `schema.ce.properties_info` for a non-static, non-virtual slot.
        let zval =
            unsafe { &*((obj as *const ZendObject as *const u8).add(offset) as *const Zval) };
        let ty = unsafe { zval.u1.v.type_ };
        if ty == IS_UNDEF as u8 || ty == IS_NULL as u8 {
            return None;
        }
        if ty >= IS_REFERENCE as u8 {
            let d = zval.dereference();
            if unsafe { d.u1.v.type_ } == IS_NULL as u8 {
                return None;
            }
            return Some(d);
        }
        return Some(zval);
    }
    obj.get_property::<&Zval>(&f.prop)
        .ok()
        .map(fast_deref)
        .filter(|v| unsafe { v.u1.v.type_ } != IS_NULL as u8)
}

#[inline(always)]
fn ht_count(ht: &ZendHashTable) -> usize {
    let flags = unsafe { ht.u.flags };
    if (flags & HASH_FLAG_HAS_EMPTY_IND) == 0 {
        ht.nNumOfElements as usize
    } else {
        ht.len()
    }
}

#[cold]
#[inline(never)]
fn zval_to_i64_slow(val: &Zval) -> PhpResult<i64> {
    if let Some(b) = val.bool() {
        Ok(i64::from(b))
    } else if let Some(f) = val.double() {
        Ok(f as i64)
    } else {
        Err(encode_exception("omnibuf: expected integer value"))
    }
}

#[inline(always)]
fn zval_to_i64(val: &Zval) -> PhpResult<i64> {
    if unsafe { val.u1.v.type_ } == IS_LONG as u8 {
        return Ok(unsafe { val.value.lval });
    }
    zval_to_i64_slow(val)
}

#[cold]
#[inline(never)]
fn zval_to_f64_slow(val: &Zval) -> PhpResult<f64> {
    if let Some(n) = val.long() {
        Ok(n as f64)
    } else {
        Err(encode_exception("omnibuf: expected float value"))
    }
}

#[inline(always)]
fn zval_to_f64(val: &Zval) -> PhpResult<f64> {
    if unsafe { val.u1.v.type_ } == IS_DOUBLE as u8 {
        return Ok(unsafe { val.value.dval });
    }
    zval_to_f64_slow(val)
}

#[inline(always)]
fn zval_string_bytes(val: &Zval) -> PhpResult<&[u8]> {
    if unsafe { val.u1.v.type_ } == IS_STRING as u8 {
        // SAFETY: `IS_STRING` guarantees `val.value.str_` points to a valid `ZendStr`.
        let zs = unsafe { &*val.value.str_ };
        let s = zs
            .as_str()
            .map_err(|_| encode_exception("omnibuf: string contains invalid UTF-8"))?;
        return Ok(s.as_bytes());
    }
    Err(encode_exception("omnibuf: expected string value"))
}

#[inline(always)]
fn zval_raw_bytes(val: &Zval) -> PhpResult<&[u8]> {
    if unsafe { val.u1.v.type_ } == IS_STRING as u8 {
        // SAFETY: `IS_STRING` guarantees `val.value.str_` points to a valid `ZendStr`.
        let zs = unsafe { &*val.value.str_ };
        return Ok(zs.as_bytes());
    }
    Err(encode_exception("omnibuf: expected bytes string value"))
}

#[inline(always)]
fn zval_bool(val: &Zval) -> bool {
    let ty = unsafe { val.u1.v.type_ };
    if ty == IS_TRUE as u8 {
        true
    } else if ty == IS_FALSE as u8 {
        false
    } else {
        val.bool().unwrap_or(false)
    }
}

#[inline(always)]
fn write_scalar_value_rev(rev: &mut RevBuf<'_>, kind: ScalarKind, val: &Zval) -> PhpResult<()> {
    let v = fast_deref(val);
    match kind {
        ScalarKind::Uint32 => {
            let n = (zval_to_i64(v)? as u64) & 0xFFFF_FFFF;
            rev.write_varint(n);
        }
        ScalarKind::Uint64 => {
            let n = zval_to_i64(v)? as u64;
            rev.write_varint(n);
        }
        ScalarKind::Sint32 => {
            let n = i64::from(zval_to_i64(v)? as i32);
            rev.write_varint(encode_zigzag(n));
        }
        ScalarKind::Sint64 => {
            let n = zval_to_i64(v)?;
            rev.write_varint(encode_zigzag(n));
        }
        ScalarKind::Bool => {
            rev.write_u8(u8::from(zval_bool(v)));
        }
        ScalarKind::Fixed32 => {
            let n = zval_to_i64(v)? as u32;
            rev.write_u32_le(n);
        }
        ScalarKind::Fixed64 => {
            let n = zval_to_i64(v)? as u64;
            rev.write_u64_le(n);
        }
        ScalarKind::Sfixed32 => {
            let n = zval_to_i64(v)? as i32;
            rev.write_u32_le(n as u32);
        }
        ScalarKind::Sfixed64 => {
            let n = zval_to_i64(v)?;
            rev.write_u64_le(n as u64);
        }
        ScalarKind::Float32 => {
            let f = zval_to_f64(v)? as f32;
            rev.write_u32_le(f.to_bits());
        }
        ScalarKind::Float64 => {
            let f = zval_to_f64(v)?;
            rev.write_u64_le(f.to_bits());
        }
        ScalarKind::String => {
            let bytes = zval_string_bytes(v)?;
            rev.write_bytes(bytes);
            rev.write_varint(bytes.len() as u64);
        }
        ScalarKind::Bytes => {
            let bytes = zval_raw_bytes(v)?;
            rev.write_bytes(bytes);
            rev.write_varint(bytes.len() as u64);
        }
    }
    Ok(())
}

#[inline(always)]
fn write_elem_rev(
    rev: &mut RevBuf<'_>,
    schemas: &[SchemaDef],
    elem: ElemKind,
    val: &Zval,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    match elem {
        ElemKind::Scalar(s) => write_scalar_value_rev(rev, s, val),
        ElemKind::SubMsg(sub_id) => {
            let v = fast_deref(val);
            if unsafe { v.u1.v.type_ } != IS_OBJECT as u8 {
                return Err(encode_exception("omnibuf: expected submessage object"));
            }
            // SAFETY: `IS_OBJECT` guarantees `v.value.obj` is a valid `ZendObject`.
            let obj = unsafe { &*v.value.obj };
            let end_written = rev.written();
            encode_object_rev(rev, schemas, sub_id, obj, deterministic, depth + 1)?;
            let body_len = rev.written() - end_written;
            rev.write_container_len(body_len);
            Ok(())
        }
    }
}

#[derive(Clone, Copy)]
enum MapKeyRef<'a> {
    Long(i64),
    ZendStr(&'a ZendStr),
}

impl MapKeyRef<'_> {
    #[inline]
    fn as_i64(self) -> PhpResult<i64> {
        match self {
            Self::Long(n) => Ok(n),
            Self::ZendStr(zs) => zs
                .as_str()
                .ok()
                .and_then(|st| st.parse::<i64>().ok())
                .ok_or_else(|| encode_exception("omnibuf: invalid integer map key")),
        }
    }
}

fn write_map_key_rev(rev: &mut RevBuf<'_>, kind: ScalarKind, key: MapKeyRef<'_>) -> PhpResult<()> {
    match kind {
        ScalarKind::String => match key {
            MapKeyRef::ZendStr(zs) => {
                let s = zs
                    .as_str()
                    .map_err(|_| encode_exception("omnibuf: map key contains invalid UTF-8"))?;
                let bytes = s.as_bytes();
                rev.write_bytes(bytes);
                rev.write_varint(bytes.len() as u64);
            }
            MapKeyRef::Long(n) => {
                let s = n.to_string();
                let bytes = s.as_bytes();
                rev.write_bytes(bytes);
                rev.write_varint(bytes.len() as u64);
            }
        },
        ScalarKind::Bool => {
            let b = match key {
                MapKeyRef::Long(n) => n != 0,
                MapKeyRef::ZendStr(zs) => {
                    let bytes = zs.as_bytes();
                    !bytes.is_empty() && bytes != b"0"
                }
            };
            rev.write_u8(u8::from(b));
        }
        _ => {
            let n = key.as_i64()?;
            match kind {
                ScalarKind::Uint32 => rev.write_varint((n as u64) & 0xFFFF_FFFF),
                ScalarKind::Uint64 => rev.write_varint(n as u64),
                ScalarKind::Sint32 => rev.write_varint(encode_zigzag(i64::from(n as i32))),
                ScalarKind::Sint64 => rev.write_varint(encode_zigzag(n)),
                ScalarKind::Fixed32 => rev.write_u32_le(n as u32),
                ScalarKind::Fixed64 => rev.write_u64_le(n as u64),
                ScalarKind::Sfixed32 => rev.write_u32_le((n as i32) as u32),
                ScalarKind::Sfixed64 => rev.write_u64_le(n as u64),
                _ => unreachable!(),
            }
        }
    }
    Ok(())
}

fn encode_array_rev(
    rev: &mut RevBuf<'_>,
    schemas: &[SchemaDef],
    ht: &ZendHashTable,
    count: usize,
    elem_kind: ElemKind,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    let n_used = ht.nNumUsed as usize;
    let is_packed = (unsafe { ht.u.flags } & HASH_FLAG_PACKED) != 0;

    if count > 0 {
        if is_packed {
            // SAFETY: `count > 0` guarantees `arPacked` is initialized with `n_used` slots.
            let ar_packed = unsafe { ht.__bindgen_anon_1.arPacked };
            match elem_kind {
                ElemKind::Scalar(ScalarKind::Uint32) => {
                    for i in (0..n_used).rev() {
                        let item = unsafe { &*ar_packed.add(i) };
                        let ty = unsafe { item.u1.v.type_ };
                        if ty == IS_UNDEF as u8 {
                            continue;
                        }
                        let v = if ty >= IS_REFERENCE as u8 {
                            item.dereference()
                        } else {
                            item
                        };
                        let n = (zval_to_i64(v)? as u64) & 0xFFFF_FFFF;
                        rev.write_varint(n);
                    }
                }
                ElemKind::Scalar(ScalarKind::String) => {
                    for i in (0..n_used).rev() {
                        let item = unsafe { &*ar_packed.add(i) };
                        let ty = unsafe { item.u1.v.type_ };
                        if ty == IS_UNDEF as u8 {
                            continue;
                        }
                        let v = if ty >= IS_REFERENCE as u8 {
                            item.dereference()
                        } else {
                            item
                        };
                        let bytes = zval_string_bytes(v)?;
                        rev.write_bytes(bytes);
                        rev.write_varint(bytes.len() as u64);
                    }
                }
                ElemKind::SubMsg(sub_id) => {
                    for i in (0..n_used).rev() {
                        let item = unsafe { &*ar_packed.add(i) };
                        let ty = unsafe { item.u1.v.type_ };
                        if ty == IS_UNDEF as u8 {
                            continue;
                        }
                        let v = if ty >= IS_REFERENCE as u8 {
                            item.dereference()
                        } else {
                            item
                        };
                        if unsafe { v.u1.v.type_ } != IS_OBJECT as u8 {
                            return Err(encode_exception("omnibuf: expected submessage object"));
                        }
                        let obj = unsafe { &*v.value.obj };
                        let end_written = rev.written();
                        encode_object_rev(rev, schemas, sub_id, obj, deterministic, depth + 1)?;
                        let body_len = rev.written() - end_written;
                        rev.write_container_len(body_len);
                    }
                }
                _ => {
                    for i in (0..n_used).rev() {
                        let item = unsafe { &*ar_packed.add(i) };
                        if unsafe { item.u1.v.type_ } == IS_UNDEF as u8 {
                            continue;
                        }
                        write_elem_rev(rev, schemas, elem_kind, item, deterministic, depth)?;
                    }
                }
            }
        } else {
            // SAFETY: `count > 0` and non-packed guarantees `arData` is initialized with `n_used` buckets.
            let ar_data = unsafe { ht.__bindgen_anon_1.arData };
            for i in (0..n_used).rev() {
                let bucket = unsafe { &*ar_data.add(i) };
                if unsafe { bucket.val.u1.v.type_ } == IS_UNDEF as u8 {
                    continue;
                }
                write_elem_rev(rev, schemas, elem_kind, &bucket.val, deterministic, depth)?;
            }
        }
    }

    rev.write_varint(count as u64);
    rev.write_u8(elem_kind.wire_type());
    Ok(())
}

fn encode_map_rev(
    rev: &mut RevBuf<'_>,
    schemas: &[SchemaDef],
    ht: &ZendHashTable,
    count: usize,
    key_kind: ScalarKind,
    val_kind: ElemKind,
    deterministic: bool,
    depth: usize,
) -> PhpResult<()> {
    let n_used = ht.nNumUsed as usize;
    let is_packed = (unsafe { ht.u.flags } & HASH_FLAG_PACKED) != 0;

    if count > 0 {
        if deterministic && count > 1 {
            let mut entries: Vec<(MapKeyRef<'_>, &Zval)> = Vec::with_capacity(count);
            if is_packed {
                let ar_packed = unsafe { ht.__bindgen_anon_1.arPacked };
                for i in 0..n_used {
                    let val = unsafe { &*ar_packed.add(i) };
                    if unsafe { val.u1.v.type_ } == IS_UNDEF as u8 {
                        continue;
                    }
                    entries.push((MapKeyRef::Long(i as i64), val));
                }
            } else {
                let ar_data = unsafe { ht.__bindgen_anon_1.arData };
                for i in 0..n_used {
                    let bucket = unsafe { &*ar_data.add(i) };
                    if unsafe { bucket.val.u1.v.type_ } == IS_UNDEF as u8 {
                        continue;
                    }
                    let k = if bucket.key.is_null() {
                        MapKeyRef::Long(bucket.h as i64)
                    } else {
                        MapKeyRef::ZendStr(unsafe { &*bucket.key })
                    };
                    entries.push((k, &bucket.val));
                }
            }

            match key_kind {
                ScalarKind::String => entries.sort_by(|(a, _), (b, _)| match (a, b) {
                    (MapKeyRef::ZendStr(sa), MapKeyRef::ZendStr(sb)) => {
                        sa.as_bytes().cmp(sb.as_bytes())
                    }
                    (MapKeyRef::ZendStr(sa), MapKeyRef::Long(nb)) => {
                        sa.as_bytes().cmp(nb.to_string().as_bytes())
                    }
                    (MapKeyRef::Long(na), MapKeyRef::ZendStr(sb)) => {
                        na.to_string().as_bytes().cmp(sb.as_bytes())
                    }
                    (MapKeyRef::Long(na), MapKeyRef::Long(nb)) => {
                        na.to_string().as_bytes().cmp(nb.to_string().as_bytes())
                    }
                }),
                ScalarKind::Uint32
                | ScalarKind::Uint64
                | ScalarKind::Fixed32
                | ScalarKind::Fixed64 => {
                    entries.sort_by_key(|(k, _)| k.as_i64().unwrap_or(0) as u64);
                }
                _ => {
                    entries.sort_by_key(|(k, _)| k.as_i64().unwrap_or(0));
                }
            }

            for (k, v) in entries.into_iter().rev() {
                write_elem_rev(rev, schemas, val_kind, v, deterministic, depth)?;
                write_map_key_rev(rev, key_kind, k)?;
            }
        } else if is_packed {
            let ar_packed = unsafe { ht.__bindgen_anon_1.arPacked };
            for i in (0..n_used).rev() {
                let val = unsafe { &*ar_packed.add(i) };
                if unsafe { val.u1.v.type_ } == IS_UNDEF as u8 {
                    continue;
                }
                write_elem_rev(rev, schemas, val_kind, val, deterministic, depth)?;
                write_map_key_rev(rev, key_kind, MapKeyRef::Long(i as i64))?;
            }
        } else {
            let ar_data = unsafe { ht.__bindgen_anon_1.arData };
            for i in (0..n_used).rev() {
                let bucket = unsafe { &*ar_data.add(i) };
                if unsafe { bucket.val.u1.v.type_ } == IS_UNDEF as u8 {
                    continue;
                }
                let k = if bucket.key.is_null() {
                    MapKeyRef::Long(bucket.h as i64)
                } else {
                    MapKeyRef::ZendStr(unsafe { &*bucket.key })
                };
                write_elem_rev(rev, schemas, val_kind, &bucket.val, deterministic, depth)?;
                write_map_key_rev(rev, key_kind, k)?;
            }
        }
    }

    rev.write_varint(count as u64);
    rev.write_u8(val_kind.wire_type());
    rev.write_u8(key_kind.wire_type());
    Ok(())
}

fn encode_object_rev(
    rev: &mut RevBuf<'_>,
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

    if !std::ptr::eq(obj.ce as *const _, schema.ce as *const _) {
        let actual_class = obj.get_class_name().unwrap_or_default();
        return Err(encode_exception(&format!(
            "omnibuf: expected instance of {}, got {}",
            schema.class_name, actual_class
        )));
    }

    for f in schema.fields.iter().rev() {
        let Some(val) = read_field_zval(obj, f) else {
            continue;
        };

        let check_zero = f.omit_empty && !f.nullable;

        match f.kind {
            FieldKind::SubMsg(sub_id) => {
                if unsafe { val.u1.v.type_ } != IS_OBJECT as u8 {
                    return Err(encode_exception("omnibuf: expected submessage object"));
                }
                let sub_obj = unsafe { &*val.value.obj };
                let end_written = rev.written();
                encode_object_rev(rev, schemas, sub_id, sub_obj, deterministic, depth + 1)?;
                let body_len = rev.written() - end_written;
                rev.write_container_len(body_len);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Array(elem_kind) => {
                if unsafe { val.u1.v.type_ } != IS_ARRAY as u8 {
                    continue;
                }
                let ht = unsafe { &*val.value.arr };
                let count = ht_count(ht);
                if count == 0 && f.omit_empty {
                    continue;
                }
                let end_written = rev.written();
                encode_array_rev(rev, schemas, ht, count, elem_kind, deterministic, depth)?;
                let body_len = rev.written() - end_written;
                rev.write_container_len(body_len);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Map { key, val: val_kind } => {
                if unsafe { val.u1.v.type_ } != IS_ARRAY as u8 {
                    continue;
                }
                let ht = unsafe { &*val.value.arr };
                let count = ht_count(ht);
                if count == 0 && f.omit_empty {
                    continue;
                }
                let end_written = rev.written();
                encode_map_rev(rev, schemas, ht, count, key, val_kind, deterministic, depth)?;
                let body_len = rev.written() - end_written;
                rev.write_container_len(body_len);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Bool) => {
                let b = zval_bool(val);
                if check_zero && !b {
                    continue;
                }
                if b {
                    rev.write_tag(&f.bool_true_tag_bytes, f.bool_true_tag_len);
                } else {
                    rev.write_tag(&f.tag_bytes, f.tag_len);
                }
            }
            FieldKind::Scalar(ScalarKind::Uint32) => {
                let n = (zval_to_i64(val)? as u64) & 0xFFFF_FFFF;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_varint(n);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Uint64) => {
                let n = zval_to_i64(val)? as u64;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_varint(n);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Sint32) => {
                let n = i64::from(zval_to_i64(val)? as i32);
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_varint(encode_zigzag(n));
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Sint64) => {
                let n = zval_to_i64(val)?;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_varint(encode_zigzag(n));
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Fixed32) => {
                let n = zval_to_i64(val)? as u32;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_u32_le(n);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Fixed64) => {
                let n = zval_to_i64(val)? as u64;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_u64_le(n);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Sfixed32) => {
                let n = zval_to_i64(val)? as i32;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_u32_le(n as u32);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Sfixed64) => {
                let n = zval_to_i64(val)?;
                if check_zero && n == 0 {
                    continue;
                }
                rev.write_u64_le(n as u64);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Float32) => {
                let bits = (zval_to_f64(val)? as f32).to_bits();
                if check_zero && bits == 0 {
                    continue;
                }
                rev.write_u32_le(bits);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Float64) => {
                let bits = zval_to_f64(val)?.to_bits();
                if check_zero && bits == 0 {
                    continue;
                }
                rev.write_u64_le(bits);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::String) => {
                if unsafe { val.u1.v.type_ } != IS_STRING as u8 {
                    return Err(encode_exception("omnibuf: expected string value"));
                }
                let zs = unsafe { &*val.value.str_ };
                if check_zero && zs.is_empty() {
                    continue;
                }
                let s = zs
                    .as_str()
                    .map_err(|_| encode_exception("omnibuf: string contains invalid UTF-8"))?;
                let bytes = s.as_bytes();
                rev.write_bytes(bytes);
                rev.write_varint(bytes.len() as u64);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
            FieldKind::Scalar(ScalarKind::Bytes) => {
                let bytes = zval_raw_bytes(val)?;
                if check_zero && bytes.is_empty() {
                    continue;
                }
                rev.write_bytes(bytes);
                rev.write_varint(bytes.len() as u64);
                rev.write_tag(&f.tag_bytes, f.tag_len);
            }
        }
    }
    Ok(())
}

pub fn encode_with_schema(
    schema_id: i64,
    obj: &ZendObject,
    deterministic: bool,
) -> PhpResult<ZBox<ZendStr>> {
    if schema_id < 0 {
        return Err(encode_exception("omnibuf: invalid schema id"));
    }
    with_schemas(|schemas| {
        ENCODE_BUF.with(|cell| {
            let mut buf = cell.borrow_mut();
            let mut rev = RevBuf::new(&mut buf);
            encode_object_rev(&mut rev, schemas, schema_id as usize, obj, deterministic, 0)?;
            let zs = ZendStr::new(rev.as_slice(), false);
            if buf.len() > MAX_RETAINED_BUF_LEN {
                buf.truncate(INITIAL_BUF_LEN);
                buf.shrink_to(INITIAL_BUF_LEN);
            }
            Ok(zs)
        })
    })
}
