use std::cell::RefCell;
use std::collections::HashMap;

use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use omnibuf_core::wire::{
    WIRE_ARRAY, WIRE_BOOL_FALSE, WIRE_BYTES, WIRE_FIXED32, WIRE_FIXED64, WIRE_FLOAT32,
    WIRE_FLOAT64, WIRE_MAP, WIRE_SFIXED32, WIRE_SFIXED64, WIRE_SINT, WIRE_STRING, WIRE_SUB_MSG,
    WIRE_UINT, append_varint,
};

use crate::encode_exception;

pub const MAX_FIELD_NUMBER: u32 = 536_870_911;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ScalarKind {
    Uint32,
    Uint64,
    Sint32,
    Sint64,
    Bool,
    Fixed32,
    Fixed64,
    Sfixed32,
    Sfixed64,
    Float32,
    Float64,
    String,
    Bytes,
}

impl ScalarKind {
    pub fn from_name(name: &str) -> Option<Self> {
        match name {
            "uint32" => Some(Self::Uint32),
            "uint64" => Some(Self::Uint64),
            "sint32" => Some(Self::Sint32),
            "sint64" => Some(Self::Sint64),
            "bool" => Some(Self::Bool),
            "fixed32" => Some(Self::Fixed32),
            "fixed64" => Some(Self::Fixed64),
            "sfixed32" => Some(Self::Sfixed32),
            "sfixed64" => Some(Self::Sfixed64),
            "float32" => Some(Self::Float32),
            "float64" => Some(Self::Float64),
            "string" => Some(Self::String),
            "bytes" => Some(Self::Bytes),
            _ => None,
        }
    }

    pub fn wire_type(self) -> u8 {
        match self {
            Self::Uint32 | Self::Uint64 => WIRE_UINT,
            Self::Sint32 | Self::Sint64 => WIRE_SINT,
            Self::Bool => WIRE_BOOL_FALSE,
            Self::Fixed32 => WIRE_FIXED32,
            Self::Fixed64 => WIRE_FIXED64,
            Self::Sfixed32 => WIRE_SFIXED32,
            Self::Sfixed64 => WIRE_SFIXED64,
            Self::Float32 => WIRE_FLOAT32,
            Self::Float64 => WIRE_FLOAT64,
            Self::String => WIRE_STRING,
            Self::Bytes => WIRE_BYTES,
        }
    }

    pub fn is_valid_map_key(self) -> bool {
        matches!(
            self,
            Self::Uint32
                | Self::Uint64
                | Self::Sint32
                | Self::Sint64
                | Self::Bool
                | Self::Fixed32
                | Self::Fixed64
                | Self::Sfixed32
                | Self::Sfixed64
                | Self::String
        )
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ElemKind {
    Scalar(ScalarKind),
    SubMsg(usize),
}

impl ElemKind {
    pub fn wire_type(self) -> u8 {
        match self {
            Self::Scalar(s) => s.wire_type(),
            Self::SubMsg(_) => WIRE_SUB_MSG,
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum FieldKind {
    Scalar(ScalarKind),
    SubMsg(usize),
    Array(ElemKind),
    Map { key: ScalarKind, val: ElemKind },
}

impl FieldKind {
    pub fn wire_type(self) -> u8 {
        match self {
            Self::Scalar(s) => s.wire_type(),
            Self::SubMsg(_) => WIRE_SUB_MSG,
            Self::Array(_) => WIRE_ARRAY,
            Self::Map { .. } => WIRE_MAP,
        }
    }
}

#[derive(Clone, Debug)]
pub struct FieldDef {
    pub number: u32,
    pub prop: String,
    pub kind: FieldKind,
    pub omit_empty: bool,
    pub nullable: bool,
    pub tag_bytes: [u8; 9],
    pub tag_len: u8,
}

#[derive(Clone, Debug)]
pub struct SchemaDef {
    pub class_name: String,
    pub fields: Vec<FieldDef>,
    pub fast_lookup: [u16; 64],
    pub overflow_lookup: HashMap<u32, usize>,
}

impl SchemaDef {
    pub fn field_index(&self, field_num: u32) -> Option<usize> {
        if (field_num as usize) < 64 {
            let idx = self.fast_lookup[field_num as usize];
            if idx == u16::MAX {
                None
            } else {
                Some(idx as usize)
            }
        } else {
            self.overflow_lookup.get(&field_num).copied()
        }
    }
}

thread_local! {
    static SCHEMAS: RefCell<Vec<SchemaDef>> = const { RefCell::new(Vec::new()) };
}

pub fn with_schemas<R>(f: impl FnOnce(&[SchemaDef]) -> R) -> R {
    SCHEMAS.with(|cell| f(&cell.borrow()))
}

fn precompute_tag(number: u32, wt: u8) -> ([u8; 9], u8) {
    let mut tmp = Vec::with_capacity(9);
    let tag = (u64::from(number) << 4) | u64::from(wt & 0x0F);
    append_varint(&mut tmp, tag);
    let mut arr = [0u8; 9];
    arr[..tmp.len()].copy_from_slice(&tmp);
    (arr, tmp.len() as u8)
}

fn parse_elem_kind(name: &str, sub_id: Option<usize>, schema_count: usize) -> PhpResult<ElemKind> {
    if name == "message" {
        let id = sub_id.ok_or_else(|| {
            encode_exception("omnibuf: message field/element requires valid subSchemaId")
        })?;
        if id >= schema_count {
            return Err(encode_exception("omnibuf: invalid subSchemaId"));
        }
        return Ok(ElemKind::SubMsg(id));
    }
    let scalar = ScalarKind::from_name(name)
        .ok_or_else(|| encode_exception(&format!("omnibuf: unsupported wire kind '{name}'")))?;
    Ok(ElemKind::Scalar(scalar))
}

/// Register a compiled PHP class schema with the Rust codec.
///
/// Each element of `fields` is an associative or indexed array with keys:
/// `number`, `prop`, `kind`, `omitEmpty`, `nullable`, and optional `elemKind`,
/// `keyKind`, `valKind`, `subSchemaId`.
pub fn register_schema(class_name: String, fields_ht: &ZendHashTable) -> PhpResult<i64> {
    let schema_count = SCHEMAS.with(|cell| cell.borrow().len());
    // Reserve the next schema ID upfront so self-recursive schemas can reference themselves.
    let next_id = schema_count;

    let mut fields = Vec::with_capacity(fields_ht.len());
    let mut fast_lookup = [u16::MAX; 64];
    let mut overflow_lookup = HashMap::new();

    for (_, item) in fields_ht.iter() {
        let Some(row) = item.dereference().array() else {
            return Err(encode_exception("omnibuf: field spec must be an array"));
        };

        let num_raw = row
            .get("number")
            .and_then(|v| v.long())
            .ok_or_else(|| encode_exception("omnibuf: field spec missing integer 'number'"))?;
        if num_raw <= 0 || num_raw > i64::from(MAX_FIELD_NUMBER) {
            return Err(encode_exception("omnibuf: field number out of valid range"));
        }
        let number = num_raw as u32;

        let prop = row
            .get("prop")
            .and_then(|v| v.str())
            .ok_or_else(|| encode_exception("omnibuf: field spec missing string 'prop'"))?
            .to_string();

        let kind_str = row
            .get("kind")
            .and_then(|v| v.str())
            .ok_or_else(|| encode_exception("omnibuf: field spec missing string 'kind'"))?;

        let omit_empty = row.get("omitEmpty").and_then(|v| v.bool()).unwrap_or(false);
        let nullable = row.get("nullable").and_then(|v| v.bool()).unwrap_or(false);
        let sub_id = match row.get("subSchemaId").and_then(|v| v.long()) {
            Some(id) if id >= 0 => Some(id as usize),
            _ => None,
        };

        let kind = match kind_str {
            "message" => {
                let id = sub_id.ok_or_else(|| {
                    encode_exception("omnibuf: message field requires valid subSchemaId")
                })?;
                if id > next_id {
                    return Err(encode_exception("omnibuf: invalid subSchemaId"));
                }
                FieldKind::SubMsg(id)
            }
            "array" => {
                let ek_str = row
                    .get("elemKind")
                    .and_then(|v| v.str())
                    .ok_or_else(|| encode_exception("omnibuf: array field missing 'elemKind'"))?;
                let ek = parse_elem_kind(ek_str, sub_id, next_id + 1)?;
                FieldKind::Array(ek)
            }
            "map" => {
                let kk_str = row
                    .get("keyKind")
                    .and_then(|v| v.str())
                    .ok_or_else(|| encode_exception("omnibuf: map field missing 'keyKind'"))?;
                let vk_str = row
                    .get("valKind")
                    .and_then(|v| v.str())
                    .ok_or_else(|| encode_exception("omnibuf: map field missing 'valKind'"))?;
                let key = ScalarKind::from_name(kk_str).ok_or_else(|| {
                    encode_exception(&format!("omnibuf: invalid map key kind '{kk_str}'"))
                })?;
                if !key.is_valid_map_key() {
                    return Err(encode_exception("omnibuf: unsupported map key wire type"));
                }
                let val = parse_elem_kind(vk_str, sub_id, next_id + 1)?;
                FieldKind::Map { key, val }
            }
            other => {
                let scalar = ScalarKind::from_name(other).ok_or_else(|| {
                    encode_exception(&format!("omnibuf: unsupported wire kind '{other}'"))
                })?;
                FieldKind::Scalar(scalar)
            }
        };

        let (tag_bytes, tag_len) = precompute_tag(number, kind.wire_type());
        let idx = fields.len();
        if (number as usize) < 64 {
            fast_lookup[number as usize] = idx as u16;
        } else {
            overflow_lookup.insert(number, idx);
        }

        fields.push(FieldDef {
            number,
            prop,
            kind,
            omit_empty,
            nullable,
            tag_bytes,
            tag_len,
        });
    }

    let def = SchemaDef {
        class_name,
        fields,
        fast_lookup,
        overflow_lookup,
    };

    SCHEMAS.with(|cell| {
        let mut list = cell.borrow_mut();
        let id = list.len();
        list.push(def);
        Ok(id as i64)
    })
}
