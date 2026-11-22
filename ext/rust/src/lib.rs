use std::ptr;
use std::slice;

use omnibuf_core::decode_blind_json;
use omnibuf_core::field0::validate_field0;
use omnibuf_core::wire::{decode_varint, decode_zigzag, read_tag_at, skip_value_opt, validate_message};

/// Allocate a Rust-owned byte buffer returned to C; free with `omnibuf_rs_free_buf`.
fn into_raw_buf(mut v: Vec<u8>, out_ptr: *mut *mut u8, out_len: *mut usize) {
    v.shrink_to_fit();
    let ptr = v.as_mut_ptr();
    let len = v.len();
    std::mem::forget(v);
    unsafe {
        *out_ptr = ptr;
        *out_len = len;
    }
}

/// Free a buffer previously returned by `omnibuf_rs_*`.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_free_buf(ptr: *mut u8, len: usize) {
    if !ptr.is_null() {
        unsafe {
            let _ = Vec::from_raw_parts(ptr, len, len);
        }
    }
}

/// Validate strict UTF-8 using Rust's SIMD-accelerated `str::from_utf8`.
/// Returns 1 if valid, 0 if invalid.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_validate_utf8(ptr: *const u8, len: usize) -> i32 {
    if len == 0 {
        return 1;
    }
    if ptr.is_null() {
        return 0;
    }
    let bytes = unsafe { slice::from_raw_parts(ptr, len) };
    if std::str::from_utf8(bytes).is_ok() { 1 } else { 0 }
}

/// Decode OmniBuf packet to canonical blind JSON via `omnibuf_core::decode_blind_json`.
/// Returns 0 on success, -1 on error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_decode_blind_json(
    raw_ptr: *const u8,
    raw_len: usize,
    out_ptr: *mut *mut u8,
    out_len: *mut usize,
) -> i32 {
    let raw = if raw_len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(raw_ptr, raw_len) }
    };
    match decode_blind_json(raw) {
        Ok(json) => {
            into_raw_buf(json.into_bytes(), out_ptr, out_len);
            0
        }
        Err(_) => -1,
    }
}

/// Find the last occurrence of `target_field` in `raw[0..raw_len]`.
/// Returns 1 if found (populating `out_wt`, `out_val_off`, `out_val_len`), 0 if not found, -1 on malformed input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_opaque_find_field(
    raw_ptr: *const u8,
    raw_len: usize,
    target_field: u64,
    out_wt: *mut u8,
    out_val_off: *mut usize,
    out_val_len: *mut usize,
) -> i32 {
    if raw_len == 0 {
        return 0;
    }
    let raw = unsafe { slice::from_raw_parts(raw_ptr, raw_len) };
    let mut pos = 0usize;
    let mut found = false;
    let mut found_wt = 0u8;
    let mut found_off = 0usize;
    let mut found_len = 0usize;

    while pos < raw.len() {
        let Some((field_num, wt, next)) = read_tag_at(raw, pos) else {
            return -1;
        };
        let Some(end) = skip_value_opt(raw, next, wt) else {
            return -1;
        };
        if u64::from(field_num) == target_field {
            found = true;
            found_wt = wt;
            found_off = next;
            found_len = end - next;
        }
        pos = end;
    }

    if found {
        unsafe {
            *out_wt = found_wt;
            *out_val_off = found_off;
            *out_val_len = found_len;
        }
        1
    } else {
        0
    }
}

/// Decode a Prefix-Varint at `raw[0..raw_len]`. Returns bytes consumed or 0 on error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_decode_varint(
    raw_ptr: *const u8,
    raw_len: usize,
    out_val: *mut u64,
) -> usize {
    if raw_len == 0 || raw_ptr.is_null() {
        return 0;
    }
    let raw = unsafe { slice::from_raw_parts(raw_ptr, raw_len) };
    match decode_varint(raw) {
        Some((v, n)) => {
            unsafe { *out_val = v };
            n
        }
        None => 0,
    }
}

/// Decode a ZigZag-encoded Prefix-Varint at `raw[0..raw_len]`. Returns bytes consumed or 0 on error.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_decode_sint(
    raw_ptr: *const u8,
    raw_len: usize,
    out_val: *mut i64,
) -> usize {
    if raw_len == 0 || raw_ptr.is_null() {
        return 0;
    }
    let raw = unsafe { slice::from_raw_parts(raw_ptr, raw_len) };
    match decode_varint(raw) {
        Some((u, n)) => {
            unsafe { *out_val = decode_zigzag(u) };
            n
        }
        None => 0,
    }
}

/// Splice dirty field patches into `raw`, preserving untouched byte spans verbatim.
/// Returns 0 on success, -1 on malformed input.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn omnibuf_rs_opaque_splice(
    prefix_ptr: *const u8,
    prefix_len: usize,
    raw_ptr: *const u8,
    raw_len: usize,
    patch_fields: *const u64,
    patch_ptrs: *const *const u8,
    patch_lens: *const usize,
    patch_count: usize,
    out_ptr: *mut *mut u8,
    out_len: *mut usize,
) -> i32 {
    let prefix = if prefix_len == 0 || prefix_ptr.is_null() {
        &[]
    } else {
        unsafe { slice::from_raw_parts(prefix_ptr, prefix_len) }
    };
    let raw = if raw_len == 0 || raw_ptr.is_null() {
        &[]
    } else {
        unsafe { slice::from_raw_parts(raw_ptr, raw_len) }
    };

    if patch_count == 0 {
        if validate_message(raw, 0).is_err() {
            return -1;
        }
        let mut out = Vec::with_capacity(prefix.len() + raw.len());
        out.extend_from_slice(prefix);
        out.extend_from_slice(raw);
        into_raw_buf(out, out_ptr, out_len);
        return 0;
    }

    let fields = unsafe { slice::from_raw_parts(patch_fields, patch_count) };
    let ptrs = unsafe { slice::from_raw_parts(patch_ptrs, patch_count) };
    let lens = unsafe { slice::from_raw_parts(patch_lens, patch_count) };

    let mut extra_bytes = 0usize;
    for &l in lens {
        extra_bytes = extra_bytes.saturating_add(l);
    }

    let mut out = Vec::with_capacity(prefix.len() + raw.len() + extra_bytes);
    out.extend_from_slice(prefix);

    let mut emitted = vec![false; patch_count];
    let mut pos = 0usize;
    let mut span_start = 0usize;

    while pos < raw.len() {
        let field_start = pos;
        let Some((field_num, wt, next)) = read_tag_at(raw, pos) else {
            return -1;
        };
        let end = if field_num == 0 {
            let Ok(f0_end) = validate_field0(raw, next, 0) else {
                return -1;
            };
            f0_end
        } else {
            let Some(e) = skip_value_opt(raw, next, wt) else {
                return -1;
            };
            e
        };
        if let Some(idx) = fields.iter().position(|&f| f == u64::from(field_num)) {
            if field_start > span_start {
                out.extend_from_slice(&raw[span_start..field_start]);
            }
            if !emitted[idx] {
                emitted[idx] = true;
                let plen = lens[idx];
                if plen > 0 && !ptrs[idx].is_null() {
                    let pbytes = unsafe { slice::from_raw_parts(ptrs[idx], plen) };
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

    // Append any dirty fields not present in original packet.
    for i in 0..patch_count {
        if !emitted[i] {
            let plen = lens[i];
            if plen > 0 && !ptrs[i].is_null() {
                let pbytes = unsafe { slice::from_raw_parts(ptrs[i], plen) };
                out.extend_from_slice(pbytes);
            }
        }
    }

    let _ = ptr::null::<u8>();
    into_raw_buf(out, out_ptr, out_len);
    0
}
