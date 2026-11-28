mod decode;
mod encode;
mod opaque;
mod schema;

use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::error::Result as ExtPhpResult;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendObject, ZendStr, Zval};
use ext_php_rs::zend::ClassEntry;
use omnibuf_core::decode_blind_json;

/// Zero-intermediate-copy binary string return value backed directly by a Zend string.
pub struct RawZendBytes(pub ZBox<ZendStr>);

impl IntoZval for RawZendBytes {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    #[inline]
    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> ExtPhpResult<()> {
        zv.set_zend_string(self.0);
        Ok(())
    }
}

pub(crate) fn encode_exception(msg: &str) -> PhpException {
    if let Some(ce) = ClassEntry::try_find("OmniBuf\\Exception\\EncodeException") {
        PhpException::new(msg.to_string(), 0, ce)
    } else {
        PhpException::from_message(msg.to_string())
    }
}

pub(crate) fn decode_exception(msg: &str) -> PhpException {
    if let Some(ce) = ClassEntry::try_find("OmniBuf\\Exception\\DecodeException") {
        PhpException::new(msg.to_string(), 0, ce)
    } else {
        PhpException::from_message(msg.to_string())
    }
}

#[php_function]
pub fn omnibuf_native_register_schema(
    class_name: String,
    fields: &ZendHashTable,
) -> PhpResult<i64> {
    schema::register_schema(class_name, fields)
}

#[php_function]
pub fn omnibuf_native_encode(
    schema_id: i64,
    obj: &ZendObject,
    deterministic: Option<bool>,
) -> PhpResult<RawZendBytes> {
    encode::encode_with_schema(schema_id, obj, deterministic.unwrap_or(false)).map(RawZendBytes)
}

#[php_function]
pub fn omnibuf_native_decode(
    schema_id: i64,
    raw: BinarySlice<'_, u8>,
    max_depth: Option<i64>,
    max_elements: Option<i64>,
) -> PhpResult<ZBox<ZendObject>> {
    decode::decode_with_schema(
        schema_id,
        raw.as_ref(),
        max_depth.unwrap_or(64),
        max_elements.unwrap_or(1_000_000),
    )
}

#[php_function]
pub fn omnibuf_native_opaque_locate_last(
    raw: BinarySlice<'_, u8>,
    start: i64,
    end: i64,
    field_num: i64,
    expected_wt: i64,
) -> PhpResult<Option<Vec<i64>>> {
    opaque::locate_last(raw.as_ref(), start, end, field_num, expected_wt)
}

#[php_function]
pub fn omnibuf_native_opaque_get_string(
    raw: BinarySlice<'_, u8>,
    start: i64,
    end: i64,
    field_num: i64,
) -> PhpResult<RawZendBytes> {
    opaque::get_string(raw.as_ref(), start, end, field_num).map(RawZendBytes)
}

#[php_function]
#[php(name = "omnibuf_native_opaque_get_uint64")]
pub fn omnibuf_native_opaque_get_uint64(
    raw: BinarySlice<'_, u8>,
    start: i64,
    end: i64,
    field_num: i64,
) -> PhpResult<i64> {
    opaque::get_uint64(raw.as_ref(), start, end, field_num)
}

#[php_function]
pub fn omnibuf_native_opaque_get_submsg_slice(
    raw: BinarySlice<'_, u8>,
    start: i64,
    end: i64,
    field_num: i64,
) -> PhpResult<Option<RawZendBytes>> {
    opaque::get_submsg_slice(raw.as_ref(), start, end, field_num).map(|opt| opt.map(RawZendBytes))
}

#[php_function]
pub fn omnibuf_native_opaque_splice(
    prefix: BinarySlice<'_, u8>,
    raw: BinarySlice<'_, u8>,
    dirty: &ZendHashTable,
) -> PhpResult<RawZendBytes> {
    opaque::splice(prefix.as_ref(), raw.as_ref(), dirty).map(RawZendBytes)
}

#[php_function]
pub fn omnibuf_native_decode_blind_json(raw: BinarySlice<'_, u8>) -> PhpResult<String> {
    decode_blind_json(raw.as_ref()).map_err(|_| decode_exception("omnibuf: blind decode error"))
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(omnibuf_native_register_schema))
        .function(wrap_function!(omnibuf_native_encode))
        .function(wrap_function!(omnibuf_native_decode))
        .function(wrap_function!(omnibuf_native_opaque_locate_last))
        .function(wrap_function!(omnibuf_native_opaque_get_string))
        .function(wrap_function!(omnibuf_native_opaque_get_uint64))
        .function(wrap_function!(omnibuf_native_opaque_get_submsg_slice))
        .function(wrap_function!(omnibuf_native_opaque_splice))
        .function(wrap_function!(omnibuf_native_decode_blind_json))
}
