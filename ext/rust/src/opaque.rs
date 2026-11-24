use ext_php_rs::binary::Binary;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable};
use omnibuf_core::field0::validate_field0;
use omnibuf_core::wire::{
    WIRE_BOOL_FALSE, WIRE_BOOL_TRUE, WIRE_STRING, WIRE_SUB_MSG, WIRE_UINT, decode_varint,
    read_tag_at, skip_value_opt, validate_message,
};

use crate::decode_exception;

fn check_slice(raw: &[u8], start: i64, end: i64) -> PhpResult<(usize, usize)> {
    if start < 0 || end < start || (end as usize) > raw.len() {
        return Err(decode_exception("omnibuf: slice bounds out of range"));
    }
    Ok((start as usize, end as usize))
}

pub fn locate_last(
    raw: &[u8],
    start: i64,
    end: i64,
    target_field: i64,
    expected_wt: i64,
) -> PhpResult<Option<Vec<i64>>> {
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

    Ok(found.map(|(vs, ve, wt)| vec![vs as i64, ve as i64, i64::from(wt)]))
}

pub fn get_string(raw: &[u8], start: i64, end: i64, target_field: i64) -> PhpResult<String> {
    let Some(hit) = locate_last(raw, start, end, target_field, i64::from(WIRE_STRING))? else {
        return Ok(String::new());
    };
    let vs = hit[0] as usize;
    let ve = hit[1] as usize;
    let slice = &raw[vs..ve];
    let (len, n) =
        decode_varint(slice).ok_or_else(|| decode_exception("omnibuf: invalid string length"))?;
    let blen = len as usize;
    if n + blen != slice.len() {
        return Err(decode_exception("omnibuf: truncated string value"));
    }
    let s = std::str::from_utf8(&slice[n..n + blen])
        .map_err(|_| decode_exception("omnibuf: invalid UTF-8 string"))?;
    Ok(s.to_string())
}

pub fn get_uint64(raw: &[u8], start: i64, end: i64, target_field: i64) -> PhpResult<i64> {
    let Some(hit) = locate_last(raw, start, end, target_field, i64::from(WIRE_UINT))? else {
        return Ok(0);
    };
    let vs = hit[0] as usize;
    let ve = hit[1] as usize;
    let (v, _) = decode_varint(&raw[vs..ve])
        .ok_or_else(|| decode_exception("omnibuf: invalid uint64 varint"))?;
    Ok(v as i64)
}

pub fn get_submsg_slice(
    raw: &[u8],
    start: i64,
    end: i64,
    target_field: i64,
) -> PhpResult<Option<Binary<u8>>> {
    if target_field <= 0 {
        return Ok(None);
    }
    let (s, e) = check_slice(raw, start, end)?;
    let slice = &raw[s..e];
    let target = target_field as u32;

    let mut pos = 0usize;
    let mut ranges: Vec<(usize, usize)> = Vec::new();
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
            ranges.push((body_start, body_end));
        }
        pos = val_end;
    }

    if ranges.is_empty() {
        return Ok(None);
    }
    let total: usize = ranges.iter().map(|(a, b)| b - a).sum();
    let mut out = Vec::with_capacity(total);
    for (a, b) in ranges {
        out.extend_from_slice(&slice[a..b]);
    }
    Ok(Some(Binary::from(out)))
}

pub fn splice(prefix: &[u8], raw: &[u8], dirty: &ZendHashTable) -> PhpResult<Binary<u8>> {
    let mut patches: Vec<(u64, Option<&[u8]>)> = Vec::with_capacity(dirty.len());
    let mut extra_bytes = 0usize;

    for (key, val) in dirty.iter() {
        let field_num = match key {
            ArrayKey::Long(n) if n >= 0 => n as u64,
            _ => {
                return Err(decode_exception(
                    "omnibuf: splice dirty map keys must be integer field numbers",
                ));
            }
        };
        let bytes = val.dereference().binary_slice();
        if let Some(b) = bytes {
            extra_bytes = extra_bytes
                .checked_add(b.len())
                .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;
        }
        patches.push((field_num, bytes));
    }

    if patches.is_empty() {
        validate_message(raw, 0)
            .map_err(|_| decode_exception("omnibuf: splice failed on malformed packet"))?;
        let cap = prefix
            .len()
            .checked_add(raw.len())
            .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;
        let mut out = Vec::with_capacity(cap);
        out.extend_from_slice(prefix);
        out.extend_from_slice(raw);
        return Ok(Binary::from(out));
    }

    let total_cap = prefix
        .len()
        .checked_add(raw.len())
        .and_then(|n| n.checked_add(extra_bytes))
        .ok_or_else(|| decode_exception("omnibuf: splice output size overflow"))?;

    let mut out = Vec::with_capacity(total_cap);
    out.extend_from_slice(prefix);

    let mut emitted = vec![false; patches.len()];
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
            if !emitted[idx] {
                emitted[idx] = true;
                if let Some(pbytes) = patches[idx].1 {
                    out.extend_from_slice(pbytes);
                }
            }
            span_start = end;
        }
        pos = end;
    }

    if span_start < raw.len() {
        out.extend_from_slice(&raw[span_start..]);
    }

    for (i, &(_, patch_bytes)) in patches.iter().enumerate() {
        if !emitted[i]
            && let Some(pbytes) = patch_bytes
        {
            out.extend_from_slice(pbytes);
        }
    }

    Ok(Binary::from(out))
}
