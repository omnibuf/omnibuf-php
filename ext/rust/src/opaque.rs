use std::cell::RefCell;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::ffi::{IS_REFERENCE, IS_STRING, IS_UNDEF, ext_php_rs_set_known_valid_utf8};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendStr};
use omnibuf_core::field0::validate_field0;
use omnibuf_core::wire::{
    WIRE_BOOL_FALSE, WIRE_BOOL_TRUE, WIRE_STRING, WIRE_SUB_MSG, WIRE_UINT, decode_varint,
    read_tag_at, skip_value_opt, validate_message,
};

use crate::decode_exception;

const HASH_FLAG_PACKED: u32 = 1 << 2;
const MAX_RETAINED_SPLICE_CAP: usize = 262_144;

thread_local! {
    static SPLICE_BUF: RefCell<Vec<u8>> = RefCell::new(Vec::with_capacity(4096));
}

#[inline(always)]
fn check_slice(raw: &[u8], start: i64, end: i64) -> PhpResult<(usize, usize)> {
    if start < 0 || end < start || (end as usize) > raw.len() {
        return Err(decode_exception("omnibuf: slice bounds out of range"));
    }
    Ok((start as usize, end as usize))
}

#[inline]
fn locate_last_inner(
    raw: &[u8],
    start: i64,
    end: i64,
    target_field: i64,
    expected_wt: i64,
) -> PhpResult<Option<(usize, usize, u8)>> {
    if target_field <= 0 {
        return Ok(None);
    }
    let (s, e) = check_slice(raw, start, end)?;
    let slice = &raw[s..e];
    let target = target_field as u32;
    let is_bool =
        expected_wt == i64::from(WIRE_BOOL_FALSE) || expected_wt == i64::from(WIRE_BOOL_TRUE);

    let mut pos = 0usize;
    let mut found: Option<(usize, usize, u8)> = None;
    while pos < slice.len() {
        let Some((field_num, wt, next)) = read_tag_at(slice, pos) else {
            return Err(decode_exception("omnibuf: malformed tag in opaque scan"));
        };
        let Some(val_end) = skip_value_opt(slice, next, wt) else {
            return Err(decode_exception("omnibuf: malformed field in opaque scan"));
        };
        if field_num == target {
            if is_bool {
                if wt == WIRE_BOOL_FALSE || wt == WIRE_BOOL_TRUE {
                    found = Some((s + next, s + val_end, wt));
                }
            } else if i64::from(wt) == expected_wt {
                found = Some((s + next, s + val_end, wt));
            }
        }
        pos = val_end;
    }

    Ok(found)
}

pub fn locate_last(
    raw: &[u8],
    start: i64,
    end: i64,
    target_field: i64,
    expected_wt: i64,
) -> PhpResult<Option<Vec<i64>>> {
    Ok(
        locate_last_inner(raw, start, end, target_field, expected_wt)?
            .map(|(vs, ve, wt)| vec![vs as i64, ve as i64, i64::from(wt)]),
    )
}

pub fn get_string(raw: &[u8], start: i64, end: i64, target_field: i64) -> PhpResult<ZBox<ZendStr>> {
    let Some((vs, ve, _)) =
        locate_last_inner(raw, start, end, target_field, i64::from(WIRE_STRING))?
    else {
        return Ok(ZendStr::new(b"", false));
    };
    let slice = &raw[vs..ve];
    let (len, n) =
        decode_varint(slice).ok_or_else(|| decode_exception("omnibuf: invalid string length"))?;
    let blen = len as usize;
    if n + blen != slice.len() {
        return Err(decode_exception("omnibuf: truncated string value"));
    }
    let bytes = &slice[n..n + blen];
    if std::str::from_utf8(bytes).is_err() {
        return Err(decode_exception("omnibuf: invalid UTF-8 string"));
    }
    let mut zs = ZendStr::new(bytes, false);
    unsafe { ext_php_rs_set_known_valid_utf8(zs.as_mut_ptr()) };
    Ok(zs)
}

pub fn get_uint64(raw: &[u8], start: i64, end: i64, target_field: i64) -> PhpResult<i64> {
    let Some((vs, ve, _)) =
        locate_last_inner(raw, start, end, target_field, i64::from(WIRE_UINT))?
    else {
        return Ok(0);
    };
    let (v, _) = decode_varint(&raw[vs..ve])
        .ok_or_else(|| decode_exception("omnibuf: invalid uint64 varint"))?;
    Ok(v as i64)
}

pub fn get_submsg_slice(
    raw: &[u8],
    start: i64,
    end: i64,
    target_field: i64,
) -> PhpResult<Option<ZBox<ZendStr>>> {
    if target_field <= 0 {
        return Ok(None);
    }
    let (s, e) = check_slice(raw, start, end)?;
    let slice = &raw[s..e];
    let target = target_field as u32;

    let mut pos = 0usize;
    let mut first: Option<(usize, usize)> = None;
    let mut extra: Option<Vec<(usize, usize)>> = None;

    while pos < slice.len() {
        let Some((field_num, wt, next)) = read_tag_at(slice, pos) else {
            return Err(decode_exception("omnibuf: malformed tag in opaque scan"));
        };
        let Some(val_end) = skip_value_opt(slice, next, wt) else {
            return Err(decode_exception("omnibuf: malformed field in opaque scan"));
        };
        if field_num == target && wt == WIRE_SUB_MSG {
            let (len, n) = decode_varint(&slice[next..val_end])
                .ok_or_else(|| decode_exception("omnibuf: invalid submessage length"))?;
            let body_start = next + n;
            let body_end = body_start + (len as usize);
            if body_end != val_end {
                return Err(decode_exception("omnibuf: truncated submessage payload"));
            }
            match first {
                None => first = Some((body_start, body_end)),
                Some(first_range) => {
                    let vec = extra.get_or_insert_with(|| vec![first_range]);
                    vec.push((body_start, body_end));
                }
            }
        }
        pos = val_end;
    }

    let Some((a, b)) = first else {
        return Ok(None);
    };
    if let Some(ranges) = extra {
        let total: usize = ranges.iter().map(|(x, y)| y - x).sum();
        let mut out = Vec::with_capacity(total);
        for (x, y) in ranges {
            out.extend_from_slice(&slice[x..y]);
        }
        Ok(Some(ZendStr::new(&out, false)))
    } else {
        Ok(Some(ZendStr::new(&slice[a..b], false)))
    }
}

#[inline(always)]
fn extract_patch_bytes(val: &ext_php_rs::types::Zval) -> Option<&[u8]> {
    let v = if unsafe { val.u1.v.type_ } >= IS_REFERENCE as u8 {
        val.dereference()
    } else {
        val
    };
    if unsafe { v.u1.v.type_ } == IS_STRING as u8 {
        let zs = unsafe { &*v.value.str_ };
        Some(zs.as_bytes())
    } else {
        None
    }
}

pub fn splice(prefix: &[u8], raw: &[u8], dirty: &ZendHashTable) -> PhpResult<ZBox<ZendStr>> {
    let mut stack_patches: [(u64, Option<&[u8]>); 8] = [(0, None); 8];
    let mut stack_len = 0usize;
    let mut heap_patches: Option<Vec<(u64, Option<&[u8]>)>> = None;
    let mut extra_bytes = 0usize;

    let n_used = dirty.nNumUsed as usize;
    let is_packed = (unsafe { dirty.u.flags } & HASH_FLAG_PACKED) != 0;

    if dirty.nNumOfElements > 0 {
        if is_packed {
            let ar_packed = unsafe { dirty.__bindgen_anon_1.arPacked };
            for i in 0..n_used {
                let val = unsafe { &*ar_packed.add(i) };
                if unsafe { val.u1.v.type_ } == IS_UNDEF as u8 {
                    continue;
                }
                let bytes = extract_patch_bytes(val);
                if let Some(b) = bytes {
                    extra_bytes = extra_bytes
                        .checked_add(b.len())
                        .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;
                }
                let entry = (i as u64, bytes);
                if let Some(ref mut vec) = heap_patches {
                    vec.push(entry);
                } else if stack_len < stack_patches.len() {
                    stack_patches[stack_len] = entry;
                    stack_len += 1;
                } else {
                    let mut vec = Vec::with_capacity(16);
                    vec.extend_from_slice(&stack_patches);
                    vec.push(entry);
                    heap_patches = Some(vec);
                }
            }
        } else {
            let ar_data = unsafe { dirty.__bindgen_anon_1.arData };
            for i in 0..n_used {
                let bucket = unsafe { &*ar_data.add(i) };
                if unsafe { bucket.val.u1.v.type_ } == IS_UNDEF as u8 {
                    continue;
                }
                if !bucket.key.is_null() || (bucket.h as i64) < 0 {
                    return Err(decode_exception(
                        "omnibuf: splice dirty map keys must be integer field numbers",
                    ));
                }
                let field_num = bucket.h;
                let bytes = extract_patch_bytes(&bucket.val);
                if let Some(b) = bytes {
                    extra_bytes = extra_bytes
                        .checked_add(b.len())
                        .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;
                }
                let entry = (field_num, bytes);
                if let Some(ref mut vec) = heap_patches {
                    vec.push(entry);
                } else if stack_len < stack_patches.len() {
                    stack_patches[stack_len] = entry;
                    stack_len += 1;
                } else {
                    let mut vec = Vec::with_capacity(16);
                    vec.extend_from_slice(&stack_patches);
                    vec.push(entry);
                    heap_patches = Some(vec);
                }
            }
        }
    }

    let patches: &[(u64, Option<&[u8]>)] = match heap_patches {
        Some(ref v) => v.as_slice(),
        None => &stack_patches[..stack_len],
    };

    if patches.is_empty() {
        validate_message(raw, 0)
            .map_err(|_| decode_exception("omnibuf: splice failed on malformed packet"))?;
        if prefix.is_empty() {
            return Ok(ZendStr::new(raw, false));
        }
        return SPLICE_BUF.with(|cell| {
            let mut out = cell.borrow_mut();
            out.clear();
            let cap = prefix
                .len()
                .checked_add(raw.len())
                .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;
            out.reserve(cap);
            out.extend_from_slice(prefix);
            out.extend_from_slice(raw);
            let zs = ZendStr::new(out.as_slice(), false);
            if out.capacity() > MAX_RETAINED_SPLICE_CAP {
                out.clear();
                out.shrink_to(4096);
            }
            Ok(zs)
        });
    }

    let total_cap = prefix
        .len()
        .checked_add(raw.len())
        .and_then(|n| n.checked_add(extra_bytes))
        .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;

    SPLICE_BUF.with(|cell| {
        let mut out = cell.borrow_mut();
        out.clear();
        out.reserve(total_cap);
        out.extend_from_slice(prefix);

        let mut emitted_mask: u64 = 0;
        let mut emitted_vec: Option<Vec<bool>> = if patches.len() > 64 {
            Some(vec![false; patches.len()])
        } else {
            None
        };

        let mut pos = 0usize;
        let mut span_start = 0usize;

        while pos < raw.len() {
            let field_start = pos;
            let Some((field_num, wt, next)) = read_tag_at(raw, pos) else {
                return Err(decode_exception(
                    "omnibuf: splice failed on malformed packet",
                ));
            };
            let end = if field_num == 0 {
                validate_field0(raw, next, 0)
                    .map_err(|_| decode_exception("omnibuf: splice failed on malformed packet"))?
            } else {
                skip_value_opt(raw, next, wt)
                    .ok_or_else(|| decode_exception("omnibuf: splice failed on malformed packet"))?
            };
            if let Some(idx) = patches.iter().position(|&(f, _)| f == u64::from(field_num)) {
                if field_start > span_start {
                    out.extend_from_slice(&raw[span_start..field_start]);
                }
                let already = match emitted_vec {
                    Some(ref mut v) => {
                        let prev = v[idx];
                        v[idx] = true;
                        prev
                    }
                    None => {
                        let bit = 1u64 << idx;
                        let prev = (emitted_mask & bit) != 0;
                        emitted_mask |= bit;
                        prev
                    }
                };
                if !already
                    && let Some(pbytes) = patches[idx].1
                {
                    out.extend_from_slice(pbytes);
                }
                span_start = end;
            }
            pos = end;
        }

        if span_start < raw.len() {
            out.extend_from_slice(&raw[span_start..]);
        }

        for (i, &(_, patch_bytes)) in patches.iter().enumerate() {
            let was_emitted = match emitted_vec {
                Some(ref v) => v[i],
                None => (emitted_mask & (1u64 << i)) != 0,
            };
            if !was_emitted
                && let Some(pbytes) = patch_bytes
            {
                out.extend_from_slice(pbytes);
            }
        }

        let zs = ZendStr::new(out.as_slice(), false);
        if out.capacity() > MAX_RETAINED_SPLICE_CAP {
            out.clear();
            out.shrink_to(4096);
        }
        Ok(zs)
    })
}
