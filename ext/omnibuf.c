#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"

#include <stdint.h>
#include <string.h>
#include <stdlib.h>

/* ---- Rust FFI declarations (omnibuf-php-rs / omnibuf_core) ---- */

extern void omnibuf_rs_free_buf(uint8_t *ptr, size_t len);
extern int32_t omnibuf_rs_validate_utf8(const uint8_t *ptr, size_t len);
extern int32_t omnibuf_rs_decode_blind_json(
    const uint8_t *raw_ptr, size_t raw_len,
    uint8_t **out_ptr, size_t *out_len);
extern int32_t omnibuf_rs_opaque_find_field(
    const uint8_t *raw_ptr, size_t raw_len, uint64_t target_field,
    uint8_t *out_wt, size_t *out_val_off, size_t *out_val_len);
extern size_t omnibuf_rs_decode_varint(
    const uint8_t *raw_ptr, size_t raw_len, uint64_t *out_val);
extern size_t omnibuf_rs_decode_sint(
    const uint8_t *raw_ptr, size_t raw_len, int64_t *out_val);
extern int32_t omnibuf_rs_opaque_splice(
    const uint8_t *prefix_ptr, size_t prefix_len,
    const uint8_t *raw_ptr, size_t raw_len,
    const uint64_t *patch_fields,
    const uint8_t *const *patch_ptrs,
    const size_t *patch_lens,
    size_t patch_count,
    uint8_t **out_ptr, size_t *out_len);

/* ---- Wire type constants (OmniBuf v0.1) ---- */

#define WT_UINT       0
#define WT_SINT       1
#define WT_BOOL_FALSE 2
#define WT_BOOL_TRUE  3
#define WT_FIXED32    4
#define WT_FIXED64    5
#define WT_SFIXED32   6
#define WT_SFIXED64   7
#define WT_FLOAT32    8
#define WT_FLOAT64    9
#define WT_STRING     10
#define WT_BYTES      11
#define WT_SUBMSG     12
#define WT_ARRAY      13
#define WT_MAP        14
#define WT_EXTENDED   15

/* ---- Field kinds for native schema-compiled codec ---- */

#define FK_UINT32       1
#define FK_UINT64       2
#define FK_SINT32       3
#define FK_SINT64       4
#define FK_BOOL         5
#define FK_FLOAT32      6
#define FK_FLOAT64      7
#define FK_STRING       8
#define FK_BYTES        9
#define FK_SUBMSG       10
#define FK_ARR_UINT32   11
#define FK_ARR_UINT64   12
#define FK_ARR_STRING   13
#define FK_ARR_SUBMSG   14

/* ---- Safety limits ---- */

#define MAX_SCHEMAS          256
#define OB_MAX_FIELD_NUM     536870911U
#define OB_LOOKUP_TABLE_MAX  4096U
#define OB_MAX_NESTING_DEPTH 32
#define OB_MAX_ELEMENTS      1048576ULL

typedef struct {
    uint32_t field_num;
    uint8_t  kind;
    int32_t  sub_schema_id;
    uint32_t prop_offset;
    uint8_t  tag_bytes[9];
    uint8_t  tag_len;
} omnibuf_field_t;

typedef struct {
    zend_class_entry *ce;
    omnibuf_field_t  *fields;
    uint32_t          field_count;
    uint32_t          max_field_num;
    uint32_t          lookup_size;
    int32_t          *field_lookup; /* field_num < lookup_size -> index in fields[], -1 if absent */
    uint32_t          scalar_max_bytes;
    uint32_t         *var_field_indices;
    uint32_t          var_field_count;
} omnibuf_schema_t;

/* ---- Per-request module globals ---- */

ZEND_BEGIN_MODULE_GLOBALS(omnibuf)
    omnibuf_schema_t schemas[MAX_SCHEMAS];
    uint32_t schema_count;
ZEND_END_MODULE_GLOBALS(omnibuf)

ZEND_DECLARE_MODULE_GLOBALS(omnibuf)

#ifdef ZTS
# define OMNIBUF_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(omnibuf, v)
#else
# define OMNIBUF_G(v) (omnibuf_globals.v)
#endif

static void ob_free_schema(omnibuf_schema_t *s) {
    if (s->fields) {
        free(s->fields);
        s->fields = NULL;
    }
    if (s->field_lookup) {
        free(s->field_lookup);
        s->field_lookup = NULL;
    }
    if (s->var_field_indices) {
        free(s->var_field_indices);
        s->var_field_indices = NULL;
    }
    s->ce = NULL;
    s->field_count = 0;
    s->max_field_num = 0;
    s->lookup_size = 0;
    s->scalar_max_bytes = 0;
    s->var_field_count = 0;
}

/* ---- Exception helpers ---- */

static zend_class_entry *ob_find_exception_ce(const char *name, size_t len) {
    zend_string *zs = zend_string_init(name, len, 0);
    zend_class_entry *ce = zend_lookup_class(zs);
    zend_string_release(zs);
    return (ce && instanceof_function(ce, zend_ce_throwable)) ? ce : zend_ce_exception;
}

static void ob_throw_encode(const char *msg) {
    zend_throw_exception(
        ob_find_exception_ce("OmniBuf\\Exception\\EncodeException", sizeof("OmniBuf\\Exception\\EncodeException") - 1),
        msg, 0);
}

static void ob_throw_decode(const char *msg) {
    zend_throw_exception(
        ob_find_exception_ce("OmniBuf\\Exception\\DecodeException", sizeof("OmniBuf\\Exception\\DecodeException") - 1),
        msg, 0);
}

static zend_always_inline bool ob_is_instance(const zend_class_entry *ce, const zend_class_entry *expected) {
    return EXPECTED(ce == expected) || UNEXPECTED(instanceof_function(ce, expected));
}

static inline int ob_add_size(size_t *acc, size_t add) {
    if (UNEXPECTED(add > SIZE_MAX - *acc)) {
        return -1;
    }
    *acc += add;
    return 0;
}

/* ---- Growable byte buffer (stack-backed for small messages) ---- */

typedef struct {
    uint8_t *data;
    size_t   len;
    size_t   cap;
    int      heap;
} ob_buf_t;

static inline void ob_buf_free(ob_buf_t *b) {
    if (b->heap && b->data) {
        efree(b->data);
        b->data = NULL;
        b->heap = 0;
    }
}

static inline int ob_buf_reserve(ob_buf_t *b, size_t extra) {
    if (UNEXPECTED(extra > SIZE_MAX - b->len)) {
        return -1;
    }
    size_t needed = b->len + extra;
    if (EXPECTED(needed <= b->cap)) {
        return 0;
    }
    size_t new_cap = b->cap > 0 ? b->cap : 64;
    while (new_cap < needed) {
        if (UNEXPECTED(new_cap > SIZE_MAX / 2)) {
            new_cap = needed;
            break;
        }
        new_cap *= 2;
    }
    if (b->heap) {
        b->data = (uint8_t *)erealloc(b->data, new_cap);
    } else {
        uint8_t *nb = (uint8_t *)emalloc(new_cap);
        if (b->len > 0) {
            memcpy(nb, b->data, b->len);
        }
        b->data = nb;
        b->heap = 1;
    }
    b->cap = new_cap;
    return 0;
}

static inline size_t ob_encode_varint_into(uint8_t *dst, uint64_t v) {
    if (EXPECTED(v <= 127)) {
        dst[0] = (uint8_t)((v << 1) | 1);
        return 1;
    } else if (v <= 16383) {
        uint16_t enc = (uint16_t)((v << 2) | 2);
        dst[0] = (uint8_t)(enc & 0xFF);
        dst[1] = (uint8_t)(enc >> 8);
        return 2;
    } else if (v <= 536870911ULL) {
        uint32_t enc = (uint32_t)((v << 3) | 4);
        memcpy(dst, &enc, 4);
        return 4;
    } else {
        dst[0] = 0x00;
        memcpy(dst + 1, &v, 8);
        return 9;
    }
}

static inline int ob_write_varint(ob_buf_t *b, uint64_t v) {
    if (UNEXPECTED(ob_buf_reserve(b, 9) != 0)) return -1;
    b->len += ob_encode_varint_into(b->data + b->len, v);
    return 0;
}

static inline int ob_write_bytes(ob_buf_t *b, const void *src, size_t n) {
    if (n == 0) return 0;
    if (UNEXPECTED(ob_buf_reserve(b, n) != 0)) return -1;
    memcpy(b->data + b->len, src, n);
    b->len += n;
    return 0;
}

static inline int ob_begin_container(ob_buf_t *b, size_t *out_pos) {
    if (UNEXPECTED(ob_buf_reserve(b, 2) != 0)) return -1;
    size_t pos = b->len;
    b->data[pos] = 0;
    b->data[pos + 1] = 0;
    b->len += 2;
    *out_pos = pos;
    return 0;
}

static inline int ob_end_container(ob_buf_t *b, size_t len_pos) {
    size_t body_len = b->len - (len_pos + 2);
    if (EXPECTED(body_len <= 16383)) {
        uint16_t enc = (uint16_t)((body_len << 2) | 2);
        b->data[len_pos] = (uint8_t)(enc & 0xFF);
        b->data[len_pos + 1] = (uint8_t)(enc >> 8);
    } else if (body_len <= 536870911ULL) {
        if (UNEXPECTED(ob_buf_reserve(b, 2) != 0)) return -1;
        memmove(b->data + len_pos + 4, b->data + len_pos + 2, body_len);
        uint32_t enc = (uint32_t)((body_len << 3) | 4);
        memcpy(b->data + len_pos, &enc, 4);
        b->len += 2;
    } else {
        if (UNEXPECTED(ob_buf_reserve(b, 7) != 0)) return -1;
        memmove(b->data + len_pos + 9, b->data + len_pos + 2, body_len);
        b->data[len_pos] = 0x00;
        uint64_t v = (uint64_t)body_len;
        memcpy(b->data + len_pos + 1, &v, 8);
        b->len += 7;
    }
    return 0;
}

static inline int ob_begin_container_4b(ob_buf_t *b, size_t *out_pos) {
    if (UNEXPECTED(ob_buf_reserve(b, 4) != 0)) return -1;
    size_t pos = b->len;
    b->len += 4;
    *out_pos = pos;
    return 0;
}

static inline int ob_end_container_4b(ob_buf_t *b, size_t len_pos) {
    size_t body_len = b->len - (len_pos + 4);
    if (EXPECTED(body_len <= 536870911ULL)) {
        uint32_t enc = (uint32_t)((body_len << 3) | 4);
        memcpy(b->data + len_pos, &enc, 4);
    } else {
        if (UNEXPECTED(ob_buf_reserve(b, 5) != 0)) return -1;
        memmove(b->data + len_pos + 9, b->data + len_pos + 4, body_len);
        b->data[len_pos] = 0x00;
        uint64_t v = (uint64_t)body_len;
        memcpy(b->data + len_pos + 1, &v, 8);
        b->len += 5;
    }
    return 0;
}

/* Computes exact lower bound (*out_min) and safe upper bound (*out_max). Returns 0 on success, -1 on error (throws EncodeException). */
static int ob_estimate_object(const omnibuf_schema_t *s, zend_object *zobj, size_t *out_max, size_t *out_min, int depth) {
    if (UNEXPECTED(depth > OB_MAX_NESTING_DEPTH)) {
        ob_throw_encode("omnibuf: encode nesting depth exceeded");
        return -1;
    }
    if (UNEXPECTED(!ob_is_instance(zobj->ce, s->ce))) {
        ob_throw_encode("omnibuf: object class mismatch for schema");
        return -1;
    }

    size_t min_b = 0;
    size_t max_b = s->scalar_max_bytes;
    uint32_t schema_count = OMNIBUF_G(schema_count);
    const omnibuf_schema_t *schemas = OMNIBUF_G(schemas);

    for (uint32_t i = 0; i < s->var_field_count; i++) {
        const omnibuf_field_t *f = &s->fields[s->var_field_indices[i]];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);

        switch (f->kind) {
            case FK_STRING:
            case FK_BYTES:
                if (Z_TYPE_P(zv) == IS_STRING && Z_STRLEN_P(zv) > 0) {
                    size_t sl = Z_STRLEN_P(zv);
                    if (UNEXPECTED(ob_add_size(&min_b, sl) != 0 ||
                                   ob_add_size(&max_b, sl) != 0 ||
                                   ob_add_size(&max_b, (size_t)f->tag_len + 9) != 0)) {
                        ob_throw_encode("omnibuf: encoded size overflow");
                        return -1;
                    }
                }
                break;
            case FK_SUBMSG:
                if (Z_TYPE_P(zv) == IS_OBJECT) {
                    if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) {
                        ob_throw_encode("omnibuf: invalid submessage schema id");
                        return -1;
                    }
                    const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                    zend_object *sub_obj = Z_OBJ_P(zv);
                    if (UNEXPECTED(!ob_is_instance(sub_obj->ce, sub->ce))) {
                        ob_throw_encode("omnibuf: submessage class mismatch");
                        return -1;
                    }
                    size_t sm = 0, sx = 0;
                    if (UNEXPECTED(ob_estimate_object(sub, sub_obj, &sx, &sm, depth + 1) != 0)) {
                        return -1;
                    }
                    if (UNEXPECTED(ob_add_size(&min_b, sm) != 0 ||
                                   ob_add_size(&max_b, sx) != 0 ||
                                   ob_add_size(&max_b, (size_t)f->tag_len + 9) != 0)) {
                        ob_throw_encode("omnibuf: encoded size overflow");
                        return -1;
                    }
                }
                break;
            case FK_ARR_STRING:
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        if (UNEXPECTED(ob_add_size(&max_b, (size_t)f->tag_len + 19) != 0)) {
                            ob_throw_encode("omnibuf: encoded size overflow");
                            return -1;
                        }
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_STRING) {
                                size_t sl = Z_STRLEN_P(elem);
                                if (UNEXPECTED(ob_add_size(&min_b, sl) != 0 ||
                                               ob_add_size(&max_b, sl) != 0 ||
                                               ob_add_size(&max_b, 9) != 0)) {
                                    ob_throw_encode("omnibuf: encoded size overflow");
                                    return -1;
                                }
                            } else {
                                if (UNEXPECTED(ob_add_size(&max_b, 1) != 0)) {
                                    ob_throw_encode("omnibuf: encoded size overflow");
                                    return -1;
                                }
                            }
                        } ZEND_HASH_FOREACH_END();
                    }
                }
                break;
            case FK_ARR_UINT32:
            case FK_ARR_UINT64:
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    uint32_t cnt = zend_hash_num_elements(Z_ARRVAL_P(zv));
                    if (cnt > 0) {
                        if (UNEXPECTED((size_t)cnt > SIZE_MAX / 9 ||
                                       ob_add_size(&min_b, (size_t)cnt) != 0 ||
                                       ob_add_size(&max_b, (size_t)f->tag_len + 19) != 0 ||
                                       ob_add_size(&max_b, (size_t)cnt * 9) != 0)) {
                            ob_throw_encode("omnibuf: encoded size overflow");
                            return -1;
                        }
                    }
                }
                break;
            case FK_ARR_SUBMSG:
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) {
                            ob_throw_encode("omnibuf: invalid submessage schema id");
                            return -1;
                        }
                        const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                        if (UNEXPECTED(ob_add_size(&max_b, (size_t)f->tag_len + 19) != 0)) {
                            ob_throw_encode("omnibuf: encoded size overflow");
                            return -1;
                        }
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_OBJECT) {
                                zend_object *eobj = Z_OBJ_P(elem);
                                if (UNEXPECTED(!ob_is_instance(eobj->ce, sub->ce))) {
                                    ob_throw_encode("omnibuf: submessage element class mismatch");
                                    return -1;
                                }
                                size_t sm = 0, sx = 0;
                                if (UNEXPECTED(ob_estimate_object(sub, eobj, &sx, &sm, depth + 2) != 0)) {
                                    return -1;
                                }
                                if (UNEXPECTED(ob_add_size(&min_b, sm) != 0 ||
                                               ob_add_size(&max_b, sx) != 0 ||
                                               ob_add_size(&max_b, 9) != 0)) {
                                    ob_throw_encode("omnibuf: encoded size overflow");
                                    return -1;
                                }
                            } else {
                                if (UNEXPECTED(ob_add_size(&max_b, 2) != 0)) {
                                    ob_throw_encode("omnibuf: encoded size overflow");
                                    return -1;
                                }
                            }
                        } ZEND_HASH_FOREACH_END();
                    }
                }
                break;
        }
    }
    if (out_min) *out_min = min_b;
    *out_max = max_b;
    return 0;
}

static size_t ob_min_body_object(const omnibuf_schema_t *s, zend_object *zobj, int depth) {
    if (UNEXPECTED(depth > OB_MAX_NESTING_DEPTH || !ob_is_instance(zobj->ce, s->ce))) {
        return 0;
    }
    size_t min_b = 0;
    uint32_t schema_count = OMNIBUF_G(schema_count);
    const omnibuf_schema_t *schemas = OMNIBUF_G(schemas);

    for (uint32_t i = 0; i < s->var_field_count; i++) {
        const omnibuf_field_t *f = &s->fields[s->var_field_indices[i]];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);
        if ((f->kind == FK_STRING || f->kind == FK_BYTES) && Z_TYPE_P(zv) == IS_STRING) {
            size_t sl = Z_STRLEN_P(zv);
            min_b = (sl > SIZE_MAX - min_b) ? SIZE_MAX : (min_b + sl);
        } else if (f->kind == FK_SUBMSG && Z_TYPE_P(zv) == IS_OBJECT &&
                   f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < schema_count) {
            const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
            if (ob_is_instance(Z_OBJ_P(zv)->ce, sub->ce)) {
                size_t sm = ob_min_body_object(sub, Z_OBJ_P(zv), depth + 1);
                min_b = (sm > SIZE_MAX - min_b) ? SIZE_MAX : (min_b + sm);
            }
        } else if (f->kind == FK_ARR_SUBMSG && Z_TYPE_P(zv) == IS_ARRAY &&
                   f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < schema_count) {
            const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
            zval *elem;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), elem) {
                ZVAL_DEREF(elem);
                if (Z_TYPE_P(elem) == IS_OBJECT && ob_is_instance(Z_OBJ_P(elem)->ce, sub->ce)) {
                    size_t sm = ob_min_body_object(sub, Z_OBJ_P(elem), depth + 2);
                    min_b = (sm > SIZE_MAX - min_b) ? SIZE_MAX : (min_b + sm);
                }
            } ZEND_HASH_FOREACH_END();
        }
    }
    return min_b;
}

/* ---- Native Encode (direct OBJ_PROP slot reads) ---- */

static int ob_encode_object(ob_buf_t *b, const omnibuf_schema_t *s, zend_object *zobj, int allow_4b, int depth) {
    if (UNEXPECTED(depth > OB_MAX_NESTING_DEPTH)) {
        ob_throw_encode("omnibuf: encode nesting depth exceeded");
        return -1;
    }
    if (UNEXPECTED(!ob_is_instance(zobj->ce, s->ce))) {
        ob_throw_encode("omnibuf: object class mismatch for schema");
        return -1;
    }

    uint32_t schema_count = OMNIBUF_G(schema_count);
    const omnibuf_schema_t *schemas = OMNIBUF_G(schemas);

    for (uint32_t i = 0; i < s->field_count; i++) {
        const omnibuf_field_t *f = &s->fields[i];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);

        switch (f->kind) {
            case FK_UINT32: {
                if (Z_TYPE_P(zv) == IS_LONG) {
                    uint32_t v32 = (uint32_t)Z_LVAL_P(zv);
                    if (v32 != 0) {
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_write_varint(b, (uint64_t)v32) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_UINT64: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                   ob_write_varint(b, (uint64_t)Z_LVAL_P(zv)) != 0)) {
                        ob_throw_encode("omnibuf: buffer overflow");
                        return -1;
                    }
                }
                break;
            }
            case FK_SINT32: {
                if (Z_TYPE_P(zv) == IS_LONG) {
                    int32_t v32 = (int32_t)Z_LVAL_P(zv);
                    if (v32 != 0) {
                        int64_t n = (int64_t)v32;
                        uint64_t zz = ((uint64_t)n << 1) ^ (uint64_t)(n >> 63);
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_write_varint(b, zz) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_SINT64: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    int64_t n = (int64_t)Z_LVAL_P(zv);
                    uint64_t zz = ((uint64_t)n << 1) ^ (uint64_t)(n >> 63);
                    if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                   ob_write_varint(b, zz) != 0)) {
                        ob_throw_encode("omnibuf: buffer overflow");
                        return -1;
                    }
                }
                break;
            }
            case FK_BOOL: {
                if (Z_TYPE_P(zv) == IS_TRUE) {
                    if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0)) {
                        ob_throw_encode("omnibuf: buffer overflow");
                        return -1;
                    }
                }
                break;
            }
            case FK_FLOAT32: {
                if (Z_TYPE_P(zv) == IS_DOUBLE) {
                    float fv = (float)Z_DVAL_P(zv);
                    uint32_t bits;
                    memcpy(&bits, &fv, 4);
                    if (bits != 0) {
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_write_bytes(b, &bits, 4) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_FLOAT64: {
                if (Z_TYPE_P(zv) == IS_DOUBLE) {
                    double dv = Z_DVAL_P(zv);
                    uint64_t bits;
                    memcpy(&bits, &dv, 8);
                    if (bits != 0) {
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_write_bytes(b, &bits, 8) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_STRING:
            case FK_BYTES: {
                if (Z_TYPE_P(zv) == IS_STRING && Z_STRLEN_P(zv) > 0) {
                    if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                   ob_write_varint(b, (uint64_t)Z_STRLEN_P(zv)) != 0 ||
                                   ob_write_bytes(b, Z_STRVAL_P(zv), Z_STRLEN_P(zv)) != 0)) {
                        ob_throw_encode("omnibuf: buffer overflow");
                        return -1;
                    }
                }
                break;
            }
            case FK_SUBMSG: {
                if (Z_TYPE_P(zv) == IS_OBJECT) {
                    if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) {
                        ob_throw_encode("omnibuf: invalid submessage schema id");
                        return -1;
                    }
                    const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                    zend_object *sub_obj = Z_OBJ_P(zv);
                    if (UNEXPECTED(!ob_is_instance(sub_obj->ce, sub->ce))) {
                        ob_throw_encode("omnibuf: submessage class mismatch");
                        return -1;
                    }
                    if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0)) {
                        ob_throw_encode("omnibuf: buffer overflow");
                        return -1;
                    }
                    size_t lp = 0;
                    if (UNEXPECTED(allow_4b && ob_min_body_object(sub, sub_obj, depth + 1) > 16383)) {
                        if (UNEXPECTED(ob_begin_container_4b(b, &lp) != 0 ||
                                       ob_encode_object(b, sub, sub_obj, 1, depth + 1) != 0 ||
                                       ob_end_container_4b(b, lp) != 0)) {
                            return -1;
                        }
                    } else {
                        if (UNEXPECTED(ob_begin_container(b, &lp) != 0 ||
                                       ob_encode_object(b, sub, sub_obj, 0, depth + 1) != 0 ||
                                       ob_end_container(b, lp) != 0)) {
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_ARR_STRING: {
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        size_t lp = 0;
                        uint8_t et = WT_STRING;
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_begin_container(b, &lp) != 0 ||
                                       ob_write_bytes(b, &et, 1) != 0 ||
                                       ob_write_varint(b, cnt) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_STRING) {
                                if (UNEXPECTED(ob_write_varint(b, (uint64_t)Z_STRLEN_P(elem)) != 0 ||
                                               ob_write_bytes(b, Z_STRVAL_P(elem), Z_STRLEN_P(elem)) != 0)) {
                                    ob_throw_encode("omnibuf: buffer overflow");
                                    return -1;
                                }
                            } else {
                                if (UNEXPECTED(ob_write_varint(b, 0) != 0)) {
                                    ob_throw_encode("omnibuf: buffer overflow");
                                    return -1;
                                }
                            }
                        } ZEND_HASH_FOREACH_END();
                        if (UNEXPECTED(ob_end_container(b, lp) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_ARR_UINT32:
            case FK_ARR_UINT64: {
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        size_t lp = 0;
                        uint8_t et = WT_UINT;
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       ob_begin_container(b, &lp) != 0 ||
                                       ob_write_bytes(b, &et, 1) != 0 ||
                                       ob_write_varint(b, cnt) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            uint64_t v = (Z_TYPE_P(elem) == IS_LONG)
                                ? (f->kind == FK_ARR_UINT32 ? (uint64_t)(uint32_t)Z_LVAL_P(elem) : (uint64_t)Z_LVAL_P(elem))
                                : 0;
                            if (UNEXPECTED(ob_write_varint(b, v) != 0)) {
                                ob_throw_encode("omnibuf: buffer overflow");
                                return -1;
                            }
                        } ZEND_HASH_FOREACH_END();
                        if (UNEXPECTED(ob_end_container(b, lp) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
            case FK_ARR_SUBMSG: {
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) {
                            ob_throw_encode("omnibuf: invalid submessage schema id");
                            return -1;
                        }
                        const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                        int arr_4b = 0;
                        zval *elem;
                        if (UNEXPECTED(allow_4b)) {
                            size_t arr_min = 0;
                            ZEND_HASH_FOREACH_VAL(ht, elem) {
                                ZVAL_DEREF(elem);
                                if (Z_TYPE_P(elem) == IS_OBJECT && ob_is_instance(Z_OBJ_P(elem)->ce, sub->ce)) {
                                    size_t sm = ob_min_body_object(sub, Z_OBJ_P(elem), depth + 2);
                                    arr_min = (sm > SIZE_MAX - arr_min) ? SIZE_MAX : (arr_min + sm);
                                }
                            } ZEND_HASH_FOREACH_END();
                            arr_4b = arr_min > 16383;
                        }

                        size_t lp = 0;
                        uint8_t et = WT_SUBMSG;
                        if (UNEXPECTED(ob_write_bytes(b, f->tag_bytes, f->tag_len) != 0 ||
                                       (arr_4b ? ob_begin_container_4b(b, &lp) : ob_begin_container(b, &lp)) != 0 ||
                                       ob_write_bytes(b, &et, 1) != 0 ||
                                       ob_write_varint(b, cnt) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_OBJECT) {
                                zend_object *eobj = Z_OBJ_P(elem);
                                if (UNEXPECTED(!ob_is_instance(eobj->ce, sub->ce))) {
                                    ob_throw_encode("omnibuf: submessage element class mismatch");
                                    return -1;
                                }
                                size_t elp = 0;
                                if (UNEXPECTED(arr_4b && ob_min_body_object(sub, eobj, depth + 2) > 16383)) {
                                    if (UNEXPECTED(ob_begin_container_4b(b, &elp) != 0 ||
                                                   ob_encode_object(b, sub, eobj, 1, depth + 2) != 0 ||
                                                   ob_end_container_4b(b, elp) != 0)) {
                                        return -1;
                                    }
                                } else {
                                    if (UNEXPECTED(ob_begin_container(b, &elp) != 0 ||
                                                   ob_encode_object(b, sub, eobj, 0, depth + 2) != 0 ||
                                                   ob_end_container(b, elp) != 0)) {
                                        return -1;
                                    }
                                }
                            } else {
                                size_t elp = 0;
                                if (UNEXPECTED(ob_begin_container(b, &elp) != 0 ||
                                               ob_end_container(b, elp) != 0)) {
                                    ob_throw_encode("omnibuf: buffer overflow");
                                    return -1;
                                }
                            }
                        } ZEND_HASH_FOREACH_END();
                        if (UNEXPECTED((arr_4b ? ob_end_container_4b(b, lp) : ob_end_container(b, lp)) != 0)) {
                            ob_throw_encode("omnibuf: buffer overflow");
                            return -1;
                        }
                    }
                }
                break;
            }
        }
    }
    return 0;
}

/* ---- Native Decode (direct OBJ_PROP slot writes) ---- */

static inline size_t ob_read_varint(const uint8_t *data, size_t len, size_t pos, uint64_t *out) {
    if (UNEXPECTED(pos >= len)) return 0;
    uint8_t b0 = data[pos];
    if (EXPECTED((b0 & 1) == 1)) {
        *out = (uint64_t)(b0 >> 1);
        return 1;
    }
    return omnibuf_rs_decode_varint(data + pos, len - pos, out);
}

static inline const omnibuf_field_t *ob_find_field(const omnibuf_schema_t *s, uint64_t field_num) {
    if (EXPECTED(field_num < s->lookup_size)) {
        int32_t idx = s->field_lookup[field_num];
        return idx >= 0 ? &s->fields[idx] : NULL;
    }
    if (field_num <= s->max_field_num) {
        for (uint32_t i = 0; i < s->field_count; i++) {
            if (s->fields[i].field_num == (uint32_t)field_num) {
                return &s->fields[i];
            }
        }
    }
    return NULL;
}

static inline int ob_wt_matches_kind(uint8_t wt, uint8_t kind) {
    switch (kind) {
        case FK_UINT32:
        case FK_UINT64:
            return wt == WT_UINT;
        case FK_SINT32:
        case FK_SINT64:
            return wt == WT_SINT;
        case FK_BOOL:
            return wt == WT_BOOL_FALSE || wt == WT_BOOL_TRUE;
        case FK_FLOAT32:
            return wt == WT_FLOAT32;
        case FK_FLOAT64:
            return wt == WT_FLOAT64;
        case FK_STRING:
            return wt == WT_STRING;
        case FK_BYTES:
            return wt == WT_BYTES;
        case FK_SUBMSG:
            return wt == WT_SUBMSG;
        case FK_ARR_UINT32:
        case FK_ARR_UINT64:
        case FK_ARR_STRING:
        case FK_ARR_SUBMSG:
            return wt == WT_ARRAY;
        default:
            return 0;
    }
}

static int ob_decode_object(
    const uint8_t *data, size_t len, const omnibuf_schema_t *s,
    zval *out_obj, int depth, uint64_t *elem_budget);

static int ob_decode_into(
    const uint8_t *data, size_t len, const omnibuf_schema_t *s,
    zend_object *zobj, int depth, uint64_t *elem_budget) {
    if (UNEXPECTED(depth > OB_MAX_NESTING_DEPTH)) return -1;

    uint32_t schema_count = OMNIBUF_G(schema_count);
    const omnibuf_schema_t *schemas = OMNIBUF_G(schemas);

    size_t pos = 0;
    while (pos < len) {
        uint64_t tag;
        size_t tn = ob_read_varint(data, len, pos, &tag);
        if (UNEXPECTED(tn == 0)) return -1;
        pos += tn;

        uint64_t field_num = tag >> 4;
        uint8_t wt = (uint8_t)(tag & 0x0F);

        if (UNEXPECTED(field_num > OB_MAX_FIELD_NUM)) return -1;
        if (UNEXPECTED(field_num == 0 && wt != WT_SUBMSG)) return -1;

        const omnibuf_field_t *f = ob_find_field(s, field_num);
        if (f != NULL && UNEXPECTED(!ob_wt_matches_kind(wt, f->kind))) {
            f = NULL;
        }

        if (f == NULL) {
            /* Skip unknown or wire-type-mismatched field */
            uint64_t skip_v;
            size_t sn;
            switch (wt) {
                case WT_BOOL_FALSE:
                case WT_BOOL_TRUE:
                    break;
                case WT_UINT:
                case WT_SINT:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0)) return -1;
                    pos += sn;
                    break;
                case WT_FIXED32:
                case WT_SFIXED32:
                case WT_FLOAT32:
                    if (UNEXPECTED(len - pos < 4)) return -1;
                    pos += 4;
                    break;
                case WT_FIXED64:
                case WT_SFIXED64:
                case WT_FLOAT64:
                    if (UNEXPECTED(len - pos < 8)) return -1;
                    pos += 8;
                    break;
                case WT_STRING:
                case WT_BYTES:
                case WT_SUBMSG:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0 || skip_v > len - pos - sn)) return -1;
                    pos += sn + (size_t)skip_v;
                    break;
                case WT_ARRAY:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0 || skip_v < 2 || skip_v > len - pos - sn)) return -1;
                    pos += sn + (size_t)skip_v;
                    break;
                case WT_MAP:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0 || skip_v < 3 || skip_v > len - pos - sn)) return -1;
                    pos += sn + (size_t)skip_v;
                    break;
                case WT_EXTENDED:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0 || skip_v < 1 || skip_v > len - pos - sn)) return -1;
                    pos += sn + (size_t)skip_v;
                    break;
                default:
                    return -1;
            }
            continue;
        }

        zval *slot = OBJ_PROP(zobj, f->prop_offset);
        switch (f->kind) {
            case FK_UINT32: {
                uint64_t v;
                size_t n = ob_read_varint(data, len, pos, &v);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                zval_ptr_dtor(slot);
                ZVAL_LONG(slot, (zend_long)(uint32_t)v);
                break;
            }
            case FK_UINT64: {
                uint64_t v;
                size_t n = ob_read_varint(data, len, pos, &v);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                zval_ptr_dtor(slot);
                ZVAL_LONG(slot, (zend_long)v);
                break;
            }
            case FK_SINT32: {
                uint64_t u;
                size_t n = ob_read_varint(data, len, pos, &u);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                int64_t sv = (int64_t)(u >> 1) ^ -(int64_t)(u & 1);
                zval_ptr_dtor(slot);
                ZVAL_LONG(slot, (zend_long)(int32_t)sv);
                break;
            }
            case FK_SINT64: {
                uint64_t u;
                size_t n = ob_read_varint(data, len, pos, &u);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                int64_t sv = (int64_t)(u >> 1) ^ -(int64_t)(u & 1);
                zval_ptr_dtor(slot);
                ZVAL_LONG(slot, (zend_long)sv);
                break;
            }
            case FK_BOOL: {
                zval_ptr_dtor(slot);
                ZVAL_BOOL(slot, wt == WT_BOOL_TRUE);
                break;
            }
            case FK_FLOAT32: {
                if (UNEXPECTED(len - pos < 4)) return -1;
                float fv;
                memcpy(&fv, data + pos, 4);
                pos += 4;
                zval_ptr_dtor(slot);
                ZVAL_DOUBLE(slot, (double)fv);
                break;
            }
            case FK_FLOAT64: {
                if (UNEXPECTED(len - pos < 8)) return -1;
                double dv;
                memcpy(&dv, data + pos, 8);
                pos += 8;
                zval_ptr_dtor(slot);
                ZVAL_DOUBLE(slot, dv);
                break;
            }
            case FK_STRING: {
                uint64_t slen;
                size_t n = ob_read_varint(data, len, pos, &slen);
                if (UNEXPECTED(n == 0 || slen > len - pos - n)) return -1;
                pos += n;
                if (UNEXPECTED(!omnibuf_rs_validate_utf8(data + pos, (size_t)slen))) return -1;
                zval_ptr_dtor(slot);
                ZVAL_STRINGL(slot, (const char *)(data + pos), (size_t)slen);
                pos += (size_t)slen;
                break;
            }
            case FK_BYTES: {
                uint64_t slen;
                size_t n = ob_read_varint(data, len, pos, &slen);
                if (UNEXPECTED(n == 0 || slen > len - pos - n)) return -1;
                pos += n;
                zval_ptr_dtor(slot);
                ZVAL_STRINGL(slot, (const char *)(data + pos), (size_t)slen);
                pos += (size_t)slen;
                break;
            }
            case FK_SUBMSG: {
                if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) return -1;
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen > len - pos - n)) return -1;
                pos += n;
                const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                ZVAL_DEREF(slot);
                if (Z_TYPE_P(slot) == IS_OBJECT && ob_is_instance(Z_OBJ_P(slot)->ce, sub->ce)) {
                    if (UNEXPECTED(ob_decode_into(data + pos, (size_t)blen, sub, Z_OBJ_P(slot), depth + 1, elem_budget) != 0)) {
                        return -1;
                    }
                } else {
                    zval sub_zv;
                    if (UNEXPECTED(ob_decode_object(data + pos, (size_t)blen, sub, &sub_zv, depth + 1, elem_budget) != 0)) {
                        return -1;
                    }
                    zval_ptr_dtor(slot);
                    ZVAL_COPY_VALUE(slot, &sub_zv);
                }
                pos += (size_t)blen;
                break;
            }
            case FK_ARR_STRING: {
                if (UNEXPECTED(depth + 1 > OB_MAX_NESTING_DEPTH)) return -1;
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen < 2 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                uint8_t elem_wt = data[pos++];
                if (UNEXPECTED(elem_wt != WT_STRING)) return -1;
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                if (cnt == 0) {
                    if (UNEXPECTED(pos != end)) return -1;
                    break;
                }
                if (UNEXPECTED(cnt > (uint64_t)(end - pos) || cnt > OB_MAX_ELEMENTS || *elem_budget > OB_MAX_ELEMENTS - cnt)) {
                    return -1;
                }
                *elem_budget += cnt;

                zval arr;
                zval *target_arr;
                int is_new_arr;
                if (Z_TYPE_P(slot) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(slot)) > 0) {
                    SEPARATE_ARRAY(slot);
                    target_arr = slot;
                    is_new_arr = 0;
                } else {
                    array_init_size(&arr, (uint32_t)cnt);
                    target_arr = &arr;
                    is_new_arr = 1;
                }

                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t elen;
                    size_t en = ob_read_varint(data, end, pos, &elen);
                    if (UNEXPECTED(en == 0 || elen > end - pos - en)) {
                        if (is_new_arr) zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += en;
                    if (UNEXPECTED(!omnibuf_rs_validate_utf8(data + pos, (size_t)elen))) {
                        if (is_new_arr) zval_ptr_dtor(&arr);
                        return -1;
                    }
                    add_next_index_stringl(target_arr, (const char *)(data + pos), (size_t)elen);
                    pos += (size_t)elen;
                }
                if (UNEXPECTED(pos != end)) {
                    if (is_new_arr) zval_ptr_dtor(&arr);
                    return -1;
                }
                if (is_new_arr) {
                    zval_ptr_dtor(slot);
                    ZVAL_COPY_VALUE(slot, &arr);
                }
                break;
            }
            case FK_ARR_UINT32:
            case FK_ARR_UINT64: {
                if (UNEXPECTED(depth + 1 > OB_MAX_NESTING_DEPTH)) return -1;
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen < 2 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                uint8_t elem_wt = data[pos++];
                if (UNEXPECTED(elem_wt != WT_UINT)) return -1;
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                if (cnt == 0) {
                    if (UNEXPECTED(pos != end)) return -1;
                    break;
                }
                if (UNEXPECTED(cnt > (uint64_t)(end - pos) || cnt > OB_MAX_ELEMENTS || *elem_budget > OB_MAX_ELEMENTS - cnt)) {
                    return -1;
                }
                *elem_budget += cnt;

                zval arr;
                zval *target_arr;
                int is_new_arr;
                if (Z_TYPE_P(slot) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(slot)) > 0) {
                    SEPARATE_ARRAY(slot);
                    target_arr = slot;
                    is_new_arr = 0;
                } else {
                    array_init_size(&arr, (uint32_t)cnt);
                    target_arr = &arr;
                    is_new_arr = 1;
                }

                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t v;
                    size_t vn = ob_read_varint(data, end, pos, &v);
                    if (UNEXPECTED(vn == 0)) {
                        if (is_new_arr) zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += vn;
                    zend_long lv = (f->kind == FK_ARR_UINT32) ? (zend_long)(uint32_t)v : (zend_long)v;
                    add_next_index_long(target_arr, lv);
                }
                if (UNEXPECTED(pos != end)) {
                    if (is_new_arr) zval_ptr_dtor(&arr);
                    return -1;
                }
                if (is_new_arr) {
                    zval_ptr_dtor(slot);
                    ZVAL_COPY_VALUE(slot, &arr);
                }
                break;
            }
            case FK_ARR_SUBMSG: {
                if (UNEXPECTED(depth + 1 > OB_MAX_NESTING_DEPTH)) return -1;
                if (UNEXPECTED(f->sub_schema_id < 0 || (uint32_t)f->sub_schema_id >= schema_count)) return -1;
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen < 2 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                uint8_t elem_wt = data[pos++];
                if (UNEXPECTED(elem_wt != WT_SUBMSG)) return -1;
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                if (cnt == 0) {
                    if (UNEXPECTED(pos != end)) return -1;
                    break;
                }
                if (UNEXPECTED(cnt > (uint64_t)(end - pos) || cnt > OB_MAX_ELEMENTS || *elem_budget > OB_MAX_ELEMENTS - cnt)) {
                    return -1;
                }
                *elem_budget += cnt;

                const omnibuf_schema_t *sub = &schemas[f->sub_schema_id];
                zval arr;
                zval *target_arr;
                int is_new_arr;
                if (Z_TYPE_P(slot) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(slot)) > 0) {
                    SEPARATE_ARRAY(slot);
                    target_arr = slot;
                    is_new_arr = 0;
                } else {
                    array_init_size(&arr, (uint32_t)cnt);
                    target_arr = &arr;
                    is_new_arr = 1;
                }

                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t elen;
                    size_t en = ob_read_varint(data, end, pos, &elen);
                    if (UNEXPECTED(en == 0 || elen > end - pos - en)) {
                        if (is_new_arr) zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += en;
                    zval elem_zv;
                    if (UNEXPECTED(ob_decode_object(data + pos, (size_t)elen, sub, &elem_zv, depth + 2, elem_budget) != 0)) {
                        if (is_new_arr) zval_ptr_dtor(&arr);
                        return -1;
                    }
                    add_next_index_zval(target_arr, &elem_zv);
                    pos += (size_t)elen;
                }
                if (UNEXPECTED(pos != end)) {
                    if (is_new_arr) zval_ptr_dtor(&arr);
                    return -1;
                }
                if (is_new_arr) {
                    zval_ptr_dtor(slot);
                    ZVAL_COPY_VALUE(slot, &arr);
                }
                break;
            }
        }
    }
    return 0;
}

static int ob_decode_object(
    const uint8_t *data, size_t len, const omnibuf_schema_t *s,
    zval *out_obj, int depth, uint64_t *elem_budget) {
    if (UNEXPECTED(depth > OB_MAX_NESTING_DEPTH)) return -1;
    if (UNEXPECTED(object_init_ex(out_obj, s->ce) != SUCCESS)) {
        ZVAL_UNDEF(out_obj);
        return -1;
    }
    if (UNEXPECTED(ob_decode_into(data, len, s, Z_OBJ_P(out_obj), depth, elem_budget) != 0)) {
        zval_ptr_dtor(out_obj);
        ZVAL_UNDEF(out_obj);
        return -1;
    }
    return 0;
}

/* ---- PHP functions ---- */

PHP_FUNCTION(omnibuf_native_register_schema) {
    zend_string *class_name;
    HashTable *fields_ht;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(class_name)
        Z_PARAM_ARRAY_HT(fields_ht)
    ZEND_PARSE_PARAMETERS_END();

    uint32_t current_count = OMNIBUF_G(schema_count);
    if (current_count >= MAX_SCHEMAS) {
        ob_throw_encode("omnibuf: max schemas exceeded");
        RETURN_THROWS();
    }

    zend_class_entry *ce = zend_lookup_class(class_name);
    if (!ce) {
        zend_throw_exception_ex(
            ob_find_exception_ce("OmniBuf\\Exception\\EncodeException", sizeof("OmniBuf\\Exception\\EncodeException") - 1),
            0, "omnibuf: class %s not found", ZSTR_VAL(class_name));
        RETURN_THROWS();
    }

    uint32_t raw_count = zend_hash_num_elements(fields_ht);
    omnibuf_schema_t tmp;
    memset(&tmp, 0, sizeof(tmp));
    tmp.ce = ce;

    if (raw_count > 0) {
        tmp.fields = (omnibuf_field_t *)calloc(raw_count, sizeof(omnibuf_field_t));
        if (!tmp.fields) {
            ob_throw_encode("omnibuf: out of memory allocating schema fields");
            RETURN_THROWS();
        }
    }

    uint32_t idx = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(fields_ht, entry) {
        ZVAL_DEREF(entry);
        if (Z_TYPE_P(entry) != IS_ARRAY) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: schema field definition must be an array");
            RETURN_THROWS();
        }
        HashTable *fht = Z_ARRVAL_P(entry);
        zval *znum = zend_hash_index_find(fht, 0);
        zval *zkind = zend_hash_index_find(fht, 1);
        zval *zprop = zend_hash_index_find(fht, 2);
        zval *zsub = zend_hash_index_find(fht, 3);

        if (!znum || !zkind || !zprop) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: schema field definition requires [field_num, kind, prop_name]");
            RETURN_THROWS();
        }
        ZVAL_DEREF(znum);
        ZVAL_DEREF(zkind);
        ZVAL_DEREF(zprop);
        if (zsub) {
            ZVAL_DEREF(zsub);
        }

        if (Z_TYPE_P(znum) != IS_LONG || Z_TYPE_P(zkind) != IS_LONG || Z_TYPE_P(zprop) != IS_STRING) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: invalid types in schema field definition");
            RETURN_THROWS();
        }

        zend_long fnum_l = Z_LVAL_P(znum);
        if (fnum_l < 1 || fnum_l > (zend_long)OB_MAX_FIELD_NUM) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: field number out of valid range 1..536870911");
            RETURN_THROWS();
        }

        zend_long kind_l = Z_LVAL_P(zkind);
        if (kind_l < FK_UINT32 || kind_l > FK_ARR_SUBMSG) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: invalid field kind");
            RETURN_THROWS();
        }

        int32_t sub_id = -1;
        if (zsub && Z_TYPE_P(zsub) != IS_NULL) {
            if (Z_TYPE_P(zsub) != IS_LONG) {
                ob_free_schema(&tmp);
                ob_throw_encode("omnibuf: sub_schema_id must be an integer or null");
                RETURN_THROWS();
            }
            zend_long sub_l = Z_LVAL_P(zsub);
            if (sub_l < -1 || sub_l > INT32_MAX) {
                ob_free_schema(&tmp);
                ob_throw_encode("omnibuf: sub_schema_id out of range");
                RETURN_THROWS();
            }
            sub_id = (int32_t)sub_l;
        }

        if (kind_l == FK_SUBMSG || kind_l == FK_ARR_SUBMSG) {
            if (sub_id < 0 || (uint32_t)sub_id >= current_count) {
                ob_free_schema(&tmp);
                ob_throw_encode("omnibuf: invalid sub_schema_id for submessage field");
                RETURN_THROWS();
            }
        }

        zend_property_info *pinfo = zend_hash_find_ptr(&ce->properties_info, Z_STR_P(zprop));
        if (!pinfo) {
            ob_free_schema(&tmp);
            zend_throw_exception_ex(
                ob_find_exception_ce("OmniBuf\\Exception\\EncodeException", sizeof("OmniBuf\\Exception\\EncodeException") - 1),
                0, "omnibuf: property %s not found on %s", Z_STRVAL_P(zprop), ZSTR_VAL(class_name));
            RETURN_THROWS();
        }
        if (pinfo->flags & ZEND_ACC_STATIC) {
            ob_free_schema(&tmp);
            zend_throw_exception_ex(
                ob_find_exception_ce("OmniBuf\\Exception\\EncodeException", sizeof("OmniBuf\\Exception\\EncodeException") - 1),
                0, "omnibuf: static property %s cannot be registered on %s", Z_STRVAL_P(zprop), ZSTR_VAL(class_name));
            RETURN_THROWS();
        }

        omnibuf_field_t *f = &tmp.fields[idx];
        f->field_num = (uint32_t)fnum_l;
        f->kind = (uint8_t)kind_l;
        f->sub_schema_id = sub_id;
        f->prop_offset = pinfo->offset;

        uint8_t wt = WT_UINT;
        switch (f->kind) {
            case FK_UINT32: case FK_UINT64: wt = WT_UINT; break;
            case FK_SINT32: case FK_SINT64: wt = WT_SINT; break;
            case FK_BOOL: wt = WT_BOOL_TRUE; break;
            case FK_FLOAT32: wt = WT_FLOAT32; break;
            case FK_FLOAT64: wt = WT_FLOAT64; break;
            case FK_STRING: wt = WT_STRING; break;
            case FK_BYTES: wt = WT_BYTES; break;
            case FK_SUBMSG: wt = WT_SUBMSG; break;
            case FK_ARR_UINT32: case FK_ARR_UINT64: case FK_ARR_STRING: case FK_ARR_SUBMSG: wt = WT_ARRAY; break;
        }
        uint64_t tag = ((uint64_t)f->field_num << 4) | (uint64_t)wt;
        f->tag_len = (uint8_t)ob_encode_varint_into(f->tag_bytes, tag);

        if (f->field_num > tmp.max_field_num) {
            tmp.max_field_num = f->field_num;
        }
        idx++;
    } ZEND_HASH_FOREACH_END();

    tmp.field_count = idx;

    if (tmp.field_count > 0) {
        tmp.lookup_size = (tmp.max_field_num <= OB_LOOKUP_TABLE_MAX)
            ? (tmp.max_field_num + 1)
            : (OB_LOOKUP_TABLE_MAX + 1);
        tmp.field_lookup = (int32_t *)malloc(tmp.lookup_size * sizeof(int32_t));
        tmp.var_field_indices = (uint32_t *)malloc(tmp.field_count * sizeof(uint32_t));
        if (!tmp.field_lookup || !tmp.var_field_indices) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: out of memory allocating schema lookup tables");
            RETURN_THROWS();
        }
        for (uint32_t i = 0; i < tmp.lookup_size; i++) {
            tmp.field_lookup[i] = -1;
        }

        uint64_t scalar_max = 0;
        for (uint32_t i = 0; i < tmp.field_count; i++) {
            const omnibuf_field_t *f = &tmp.fields[i];
            if (f->field_num < tmp.lookup_size) {
                tmp.field_lookup[f->field_num] = (int32_t)i;
            }
            switch (f->kind) {
                case FK_UINT32: case FK_SINT32: scalar_max += (uint64_t)f->tag_len + 9; break;
                case FK_UINT64: case FK_SINT64: scalar_max += (uint64_t)f->tag_len + 9; break;
                case FK_BOOL: scalar_max += (uint64_t)f->tag_len; break;
                case FK_FLOAT32: scalar_max += (uint64_t)f->tag_len + 4; break;
                case FK_FLOAT64: scalar_max += (uint64_t)f->tag_len + 8; break;
                default:
                    tmp.var_field_indices[tmp.var_field_count++] = i;
                    break;
            }
        }
        if (scalar_max > UINT32_MAX) {
            ob_free_schema(&tmp);
            ob_throw_encode("omnibuf: schema scalar size overflow");
            RETURN_THROWS();
        }
        tmp.scalar_max_bytes = (uint32_t)scalar_max;
    }

    uint32_t id = OMNIBUF_G(schema_count);
    OMNIBUF_G(schemas)[id] = tmp;
    OMNIBUF_G(schema_count) = id + 1;

    RETURN_LONG((zend_long)id);
}

PHP_FUNCTION(omnibuf_native_encode) {
    zend_long schema_id;
    zval *obj;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(schema_id)
        Z_PARAM_OBJECT(obj)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(schema_id < 0 || (uint32_t)schema_id >= OMNIBUF_G(schema_count))) {
        ob_throw_encode("omnibuf: invalid schema id");
        RETURN_THROWS();
    }

    const omnibuf_schema_t *s = &OMNIBUF_G(schemas)[schema_id];
    zend_object *zobj = Z_OBJ_P(obj);
    if (UNEXPECTED(!ob_is_instance(zobj->ce, s->ce))) {
        ob_throw_encode("omnibuf: object class mismatch for schema");
        RETURN_THROWS();
    }

    size_t max_len = 0;
    if (UNEXPECTED(ob_estimate_object(s, zobj, &max_len, NULL, 0) != 0)) {
        RETURN_THROWS();
    }

    /* Allocate the target zend_string once and encode directly into it (zero intermediate copy). */
    zend_string *zstr = zend_string_alloc(max_len, 0);
    ob_buf_t b;
    b.data = (uint8_t *)ZSTR_VAL(zstr);
    b.len = 0;
    b.cap = max_len;
    b.heap = 0;

    if (UNEXPECTED(ob_encode_object(&b, s, zobj, max_len > 16383, 0) != 0)) {
        zend_string_release_ex(zstr, 0);
        ob_buf_free(&b);
        RETURN_THROWS();
    }

    if (EXPECTED(!b.heap)) {
        ZSTR_LEN(zstr) = b.len;
        ZSTR_VAL(zstr)[b.len] = '\0';
        RETURN_NEW_STR(zstr);
    } else {
        zend_string_release_ex(zstr, 0);
        RETVAL_STRINGL((const char *)b.data, b.len);
        ob_buf_free(&b);
    }
}

PHP_FUNCTION(omnibuf_native_decode) {
    zend_long schema_id;
    zend_string *bytes;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(schema_id)
        Z_PARAM_STR(bytes)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(schema_id < 0 || (uint32_t)schema_id >= OMNIBUF_G(schema_count))) {
        ob_throw_decode("omnibuf: invalid schema id");
        RETURN_THROWS();
    }

    uint64_t elem_budget = 0;
    if (UNEXPECTED(ob_decode_object((const uint8_t *)ZSTR_VAL(bytes), ZSTR_LEN(bytes), &OMNIBUF_G(schemas)[schema_id], return_value, 0, &elem_budget) != 0)) {
        ob_throw_decode("omnibuf: decode error");
        RETURN_THROWS();
    }
}

PHP_FUNCTION(omnibuf_native_opaque_get_string) {
    zend_string *raw;
    zend_long start, end, field_num;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(raw)
        Z_PARAM_LONG(start)
        Z_PARAM_LONG(end)
        Z_PARAM_LONG(field_num)
    ZEND_PARSE_PARAMETERS_END();

    if (start < 0 || end < start || (size_t)end > ZSTR_LEN(raw)) {
        RETURN_EMPTY_STRING();
    }
    const uint8_t *base = (const uint8_t *)ZSTR_VAL(raw) + start;
    size_t slice_len = (size_t)(end - start);
    uint8_t wt = 0;
    size_t voff = 0, vlen = 0;
    int32_t r = omnibuf_rs_opaque_find_field(base, slice_len, (uint64_t)field_num, &wt, &voff, &vlen);
    if (UNEXPECTED(r < 0)) {
        ob_throw_decode("omnibuf: malformed packet in opaque_get_string");
        RETURN_THROWS();
    }
    if (r != 1 || wt != WT_STRING) {
        RETURN_EMPTY_STRING();
    }
    uint64_t slen = 0;
    size_t n = ob_read_varint(base, voff + vlen, voff, &slen);
    if (UNEXPECTED(n == 0 || n > vlen || slen > vlen - n)) {
        ob_throw_decode("omnibuf: malformed string payload in opaque_get_string");
        RETURN_THROWS();
    }
    if (UNEXPECTED(!omnibuf_rs_validate_utf8(base + voff + n, (size_t)slen))) {
        ob_throw_decode("omnibuf: invalid UTF-8 string");
        RETURN_THROWS();
    }
    RETURN_STRINGL((const char *)(base + voff + n), (size_t)slen);
}

PHP_FUNCTION(omnibuf_native_opaque_get_uint64) {
    zend_string *raw;
    zend_long start, end, field_num;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(raw)
        Z_PARAM_LONG(start)
        Z_PARAM_LONG(end)
        Z_PARAM_LONG(field_num)
    ZEND_PARSE_PARAMETERS_END();

    if (start < 0 || end < start || (size_t)end > ZSTR_LEN(raw)) {
        RETURN_LONG(0);
    }
    const uint8_t *base = (const uint8_t *)ZSTR_VAL(raw) + start;
    size_t slice_len = (size_t)(end - start);
    uint8_t wt = 0;
    size_t voff = 0, vlen = 0;
    int32_t r = omnibuf_rs_opaque_find_field(base, slice_len, (uint64_t)field_num, &wt, &voff, &vlen);
    if (UNEXPECTED(r < 0)) {
        ob_throw_decode("omnibuf: malformed packet in opaque_get_uint64");
        RETURN_THROWS();
    }
    if (r != 1 || wt != WT_UINT) {
        RETURN_LONG(0);
    }
    uint64_t val = 0;
    size_t n = ob_read_varint(base, voff + vlen, voff, &val);
    if (UNEXPECTED(n == 0 || n > vlen)) {
        ob_throw_decode("omnibuf: malformed varint in opaque_get_uint64");
        RETURN_THROWS();
    }
    RETURN_LONG((zend_long)val);
}

PHP_FUNCTION(omnibuf_native_opaque_get_submsg_range) {
    zend_string *raw;
    zend_long start, end, field_num;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(raw)
        Z_PARAM_LONG(start)
        Z_PARAM_LONG(end)
        Z_PARAM_LONG(field_num)
    ZEND_PARSE_PARAMETERS_END();

    if (start < 0 || end < start || (size_t)end > ZSTR_LEN(raw)) {
        RETURN_NULL();
    }
    const uint8_t *base = (const uint8_t *)ZSTR_VAL(raw) + start;
    size_t slice_len = (size_t)(end - start);
    uint8_t wt = 0;
    size_t voff = 0, vlen = 0;
    int32_t r = omnibuf_rs_opaque_find_field(base, slice_len, (uint64_t)field_num, &wt, &voff, &vlen);
    if (UNEXPECTED(r < 0)) {
        ob_throw_decode("omnibuf: malformed packet in opaque_get_submsg_range");
        RETURN_THROWS();
    }
    if (r != 1 || wt != WT_SUBMSG) {
        RETURN_NULL();
    }
    uint64_t blen = 0;
    size_t n = ob_read_varint(base, voff + vlen, voff, &blen);
    if (UNEXPECTED(n == 0 || n > vlen || blen > vlen - n)) {
        ob_throw_decode("omnibuf: malformed submsg payload in opaque_get_submsg_range");
        RETURN_THROWS();
    }
    array_init_size(return_value, 2);
    add_next_index_long(return_value, start + (zend_long)(voff + n));
    add_next_index_long(return_value, start + (zend_long)(voff + n + blen));
}

PHP_FUNCTION(omnibuf_native_opaque_splice) {
    zend_string *prefix, *raw;
    HashTable *dirty_ht;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STR(prefix)
        Z_PARAM_STR(raw)
        Z_PARAM_ARRAY_HT(dirty_ht)
    ZEND_PARSE_PARAMETERS_END();

    uint32_t cnt = zend_hash_num_elements(dirty_ht);
    uint64_t stack_fields[16];
    const uint8_t *stack_ptrs[16];
    size_t stack_lens[16];

    uint64_t *fields = cnt <= 16 ? stack_fields : (uint64_t *)emalloc(cnt * sizeof(uint64_t));
    const uint8_t **ptrs = cnt <= 16 ? stack_ptrs : (const uint8_t **)emalloc(cnt * sizeof(uint8_t *));
    size_t *lens = cnt <= 16 ? stack_lens : (size_t *)emalloc(cnt * sizeof(size_t));

    uint32_t i = 0;
    zend_ulong num_key;
    zend_string *str_key;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(dirty_ht, num_key, str_key, val) {
        if (UNEXPECTED(str_key != NULL)) {
            if (cnt > 16) {
                efree(fields);
                efree(ptrs);
                efree(lens);
            }
            ob_throw_decode("omnibuf: splice dirty map keys must be integer field numbers");
            RETURN_THROWS();
        }
        ZVAL_DEREF(val);
        fields[i] = (uint64_t)num_key;
        if (Z_TYPE_P(val) == IS_STRING) {
            ptrs[i] = (const uint8_t *)Z_STRVAL_P(val);
            lens[i] = Z_STRLEN_P(val);
        } else {
            ptrs[i] = NULL;
            lens[i] = 0;
        }
        i++;
    } ZEND_HASH_FOREACH_END();

    uint8_t *out_ptr = NULL;
    size_t out_len = 0;
    int32_t rc = omnibuf_rs_opaque_splice(
        (const uint8_t *)ZSTR_VAL(prefix), ZSTR_LEN(prefix),
        (const uint8_t *)ZSTR_VAL(raw), ZSTR_LEN(raw),
        fields, ptrs, lens, i,
        &out_ptr, &out_len);

    if (cnt > 16) {
        efree(fields);
        efree(ptrs);
        efree(lens);
    }

    if (rc != 0) {
        ob_throw_decode("omnibuf: splice failed on malformed packet");
        RETURN_THROWS();
    }

    RETVAL_STRINGL((const char *)out_ptr, out_len);
    omnibuf_rs_free_buf(out_ptr, out_len);
}

PHP_FUNCTION(omnibuf_native_decode_blind_json) {
    zend_string *raw;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(raw)
    ZEND_PARSE_PARAMETERS_END();

    uint8_t *out_ptr = NULL;
    size_t out_len = 0;
    if (omnibuf_rs_decode_blind_json((const uint8_t *)ZSTR_VAL(raw), ZSTR_LEN(raw), &out_ptr, &out_len) != 0) {
        ob_throw_decode("omnibuf: blind decode error");
        RETURN_THROWS();
    }
    RETVAL_STRINGL((const char *)out_ptr, out_len);
    omnibuf_rs_free_buf(out_ptr, out_len);
}

/* ---- Arginfo & Module registration ---- */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_register_schema, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, className, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, fields, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_encode, 0, 2, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, schemaId, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, obj, IS_OBJECT, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_decode, 0, 2, IS_OBJECT, 0)
    ZEND_ARG_TYPE_INFO(0, schemaId, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_opaque_str, 0, 4, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, raw, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, start, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, end, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, fieldNum, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_opaque_u64, 0, 4, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, raw, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, start, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, end, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, fieldNum, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_opaque_submsg, 0, 4, IS_ARRAY, 1)
    ZEND_ARG_TYPE_INFO(0, raw, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, start, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, end, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, fieldNum, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_opaque_splice, 0, 3, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, prefix, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, raw, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, dirty, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_blind_json, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, raw, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry omnibuf_functions[] = {
    PHP_FE(omnibuf_native_register_schema, arginfo_register_schema)
    PHP_FE(omnibuf_native_encode, arginfo_encode)
    PHP_FE(omnibuf_native_decode, arginfo_decode)
    PHP_FE(omnibuf_native_opaque_get_string, arginfo_opaque_str)
    PHP_FE(omnibuf_native_opaque_get_uint64, arginfo_opaque_u64)
    PHP_FE(omnibuf_native_opaque_get_submsg_range, arginfo_opaque_submsg)
    PHP_FE(omnibuf_native_opaque_splice, arginfo_opaque_splice)
    PHP_FE(omnibuf_native_decode_blind_json, arginfo_blind_json)
    PHP_FE_END
};

static PHP_GINIT_FUNCTION(omnibuf) {
    memset(omnibuf_globals, 0, sizeof(*omnibuf_globals));
}

PHP_RINIT_FUNCTION(omnibuf) {
    OMNIBUF_G(schema_count) = 0;
    memset(OMNIBUF_G(schemas), 0, sizeof(OMNIBUF_G(schemas)));
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(omnibuf) {
    for (uint32_t i = 0; i < OMNIBUF_G(schema_count); i++) {
        ob_free_schema(&OMNIBUF_G(schemas)[i]);
    }
    OMNIBUF_G(schema_count) = 0;
    return SUCCESS;
}

PHP_MINFO_FUNCTION(omnibuf) {
    php_info_print_table_start();
    php_info_print_table_header(2, "omnibuf native accelerator", "enabled (omnibuf-rs)");
    php_info_print_table_end();
}

zend_module_entry omnibuf_module_entry = {
    STANDARD_MODULE_HEADER,
    "omnibuf",
    omnibuf_functions,
    NULL,
    NULL,
    PHP_RINIT(omnibuf),
    PHP_RSHUTDOWN(omnibuf),
    PHP_MINFO(omnibuf),
    "0.1.0",
    PHP_MODULE_GLOBALS(omnibuf),
    PHP_GINIT(omnibuf),
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_OMNIBUF
ZEND_GET_MODULE(omnibuf)
#endif
