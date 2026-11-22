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
    int16_t          *field_lookup; /* field_num -> index in fields[], -1 if absent */
    uint32_t          scalar_max_bytes;
    uint32_t         *var_field_indices;
    uint32_t          var_field_count;
} omnibuf_schema_t;

#define MAX_SCHEMAS 256
static omnibuf_schema_t g_schemas[MAX_SCHEMAS];
static uint32_t g_schema_count = 0;

/* ---- Growable byte buffer (stack-backed for small messages) ---- */

typedef struct {
    uint8_t *data;
    size_t   len;
    size_t   cap;
    int      heap;
} ob_buf_t;

static inline void ob_buf_init(ob_buf_t *b, uint8_t *stack_buf, size_t stack_cap) {
    b->data = stack_buf;
    b->len = 0;
    b->cap = stack_cap;
    b->heap = 0;
}

static inline void ob_buf_free(ob_buf_t *b) {
    if (b->heap && b->data) {
        efree(b->data);
    }
}

static inline void ob_buf_reserve(ob_buf_t *b, size_t extra) {
    if (EXPECTED(b->len + extra <= b->cap)) {
        return;
    }
    size_t new_cap = b->cap * 2;
    while (new_cap < b->len + extra) {
        new_cap *= 2;
    }
    if (b->heap) {
        b->data = (uint8_t *)erealloc(b->data, new_cap);
    } else {
        uint8_t *nb = (uint8_t *)emalloc(new_cap);
        memcpy(nb, b->data, b->len);
        b->data = nb;
        b->heap = 1;
    }
    b->cap = new_cap;
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

static inline void ob_write_varint(ob_buf_t *b, uint64_t v) {
    ob_buf_reserve(b, 9);
    b->len += ob_encode_varint_into(b->data + b->len, v);
}

static inline void ob_write_bytes(ob_buf_t *b, const void *src, size_t n) {
    if (n == 0) return;
    ob_buf_reserve(b, n);
    memcpy(b->data + b->len, src, n);
    b->len += n;
}

static inline size_t ob_begin_container(ob_buf_t *b) {
    ob_buf_reserve(b, 2);
    size_t pos = b->len;
    b->data[pos] = 0;
    b->data[pos + 1] = 0;
    b->len += 2;
    return pos;
}

static inline void ob_end_container(ob_buf_t *b, size_t len_pos) {
    size_t body_len = b->len - (len_pos + 2);
    if (EXPECTED(body_len <= 16383)) {
        uint16_t enc = (uint16_t)((body_len << 2) | 2);
        b->data[len_pos] = (uint8_t)(enc & 0xFF);
        b->data[len_pos + 1] = (uint8_t)(enc >> 8);
    } else if (body_len <= 536870911ULL) {
        ob_buf_reserve(b, 2);
        memmove(b->data + len_pos + 4, b->data + len_pos + 2, body_len);
        uint32_t enc = (uint32_t)((body_len << 3) | 4);
        memcpy(b->data + len_pos, &enc, 4);
        b->len += 2;
    } else {
        ob_buf_reserve(b, 7);
        memmove(b->data + len_pos + 9, b->data + len_pos + 2, body_len);
        b->data[len_pos] = 0x00;
        uint64_t v = (uint64_t)body_len;
        memcpy(b->data + len_pos + 1, &v, 8);
        b->len += 7;
    }
}

static inline size_t ob_begin_container_4b(ob_buf_t *b) {
    ob_buf_reserve(b, 4);
    size_t pos = b->len;
    b->len += 4;
    return pos;
}

static inline void ob_end_container_4b(ob_buf_t *b, size_t len_pos) {
    size_t body_len = b->len - (len_pos + 4);
    if (EXPECTED(body_len <= 536870911ULL)) {
        uint32_t enc = (uint32_t)((body_len << 3) | 4);
        memcpy(b->data + len_pos, &enc, 4);
    } else {
        ob_buf_reserve(b, 5);
        memmove(b->data + len_pos + 9, b->data + len_pos + 4, body_len);
        b->data[len_pos] = 0x00;
        uint64_t v = (uint64_t)body_len;
        memcpy(b->data + len_pos + 1, &v, 8);
        b->len += 5;
    }
}

/* Returns exact lower bound (raw payload bytes) in *out_min, and safe upper bound in return value. */
static size_t ob_estimate_object(const omnibuf_schema_t *s, zend_object *zobj, size_t *out_min) {
    size_t min_b = 0;
    size_t max_b = s->scalar_max_bytes;

    for (uint32_t i = 0; i < s->var_field_count; i++) {
        const omnibuf_field_t *f = &s->fields[s->var_field_indices[i]];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);

        switch (f->kind) {
            case FK_STRING:
            case FK_BYTES:
                if (Z_TYPE_P(zv) == IS_STRING && Z_STRLEN_P(zv) > 0) {
                    size_t sl = Z_STRLEN_P(zv);
                    min_b += sl;
                    max_b += sl + f->tag_len + 9;
                }
                break;
            case FK_SUBMSG:
                if (Z_TYPE_P(zv) == IS_OBJECT && f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < g_schema_count) {
                    size_t sm = 0;
                    size_t sx = ob_estimate_object(&g_schemas[f->sub_schema_id], Z_OBJ_P(zv), &sm);
                    min_b += sm;
                    max_b += sx + f->tag_len + 9;
                }
                break;
            case FK_ARR_STRING:
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        max_b += f->tag_len + 19;
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_STRING) {
                                size_t sl = Z_STRLEN_P(elem);
                                min_b += sl;
                                max_b += sl + 9;
                            } else {
                                max_b += 1;
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
                        min_b += cnt;
                        max_b += f->tag_len + 19 + (size_t)cnt * 9;
                    }
                }
                break;
            case FK_ARR_SUBMSG:
                if (Z_TYPE_P(zv) == IS_ARRAY && f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < g_schema_count) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        const omnibuf_schema_t *sub = &g_schemas[f->sub_schema_id];
                        max_b += f->tag_len + 19;
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_OBJECT) {
                                size_t sm = 0;
                                size_t sx = ob_estimate_object(sub, Z_OBJ_P(elem), &sm);
                                min_b += sm;
                                max_b += sx + 9;
                            } else {
                                max_b += 2;
                            }
                        } ZEND_HASH_FOREACH_END();
                    }
                }
                break;
        }
    }
    if (out_min) *out_min = min_b;
    return max_b;
}

static size_t ob_min_body_object(const omnibuf_schema_t *s, zend_object *zobj) {
    size_t min_b = 0;
    for (uint32_t i = 0; i < s->var_field_count; i++) {
        const omnibuf_field_t *f = &s->fields[s->var_field_indices[i]];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);
        if ((f->kind == FK_STRING || f->kind == FK_BYTES) && Z_TYPE_P(zv) == IS_STRING) {
            min_b += Z_STRLEN_P(zv);
        } else if (f->kind == FK_SUBMSG && Z_TYPE_P(zv) == IS_OBJECT && f->sub_schema_id >= 0) {
            min_b += ob_min_body_object(&g_schemas[f->sub_schema_id], Z_OBJ_P(zv));
        } else if (f->kind == FK_ARR_SUBMSG && Z_TYPE_P(zv) == IS_ARRAY && f->sub_schema_id >= 0) {
            const omnibuf_schema_t *sub = &g_schemas[f->sub_schema_id];
            zval *elem;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zv), elem) {
                ZVAL_DEREF(elem);
                if (Z_TYPE_P(elem) == IS_OBJECT) {
                    min_b += ob_min_body_object(sub, Z_OBJ_P(elem));
                }
            } ZEND_HASH_FOREACH_END();
        }
    }
    return min_b;
}

/* ---- Native Encode (direct OBJ_PROP slot reads) ---- */

static void ob_encode_object(ob_buf_t *b, const omnibuf_schema_t *s, zend_object *zobj, int allow_4b) {
    for (uint32_t i = 0; i < s->field_count; i++) {
        const omnibuf_field_t *f = &s->fields[i];
        zval *zv = OBJ_PROP(zobj, f->prop_offset);
        ZVAL_DEREF(zv);

        switch (f->kind) {
            case FK_UINT32: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    ob_write_varint(b, (uint64_t)(uint32_t)Z_LVAL_P(zv));
                }
                break;
            }
            case FK_UINT64: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    ob_write_varint(b, (uint64_t)Z_LVAL_P(zv));
                }
                break;
            }
            case FK_SINT32: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    int64_t n = (int64_t)(int32_t)Z_LVAL_P(zv);
                    uint64_t zz = (uint64_t)((n << 1) ^ (n >> 63));
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    ob_write_varint(b, zz);
                }
                break;
            }
            case FK_SINT64: {
                if (Z_TYPE_P(zv) == IS_LONG && Z_LVAL_P(zv) != 0) {
                    int64_t n = (int64_t)Z_LVAL_P(zv);
                    uint64_t zz = (uint64_t)((n << 1) ^ (n >> 63));
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    ob_write_varint(b, zz);
                }
                break;
            }
            case FK_BOOL: {
                if (Z_TYPE_P(zv) == IS_TRUE) {
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                }
                break;
            }
            case FK_FLOAT32: {
                if (Z_TYPE_P(zv) == IS_DOUBLE) {
                    float fv = (float)Z_DVAL_P(zv);
                    uint32_t bits;
                    memcpy(&bits, &fv, 4);
                    if (bits != 0) {
                        ob_write_bytes(b, f->tag_bytes, f->tag_len);
                        ob_write_bytes(b, &bits, 4);
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
                        ob_write_bytes(b, f->tag_bytes, f->tag_len);
                        ob_write_bytes(b, &bits, 8);
                    }
                }
                break;
            }
            case FK_STRING:
            case FK_BYTES: {
                if (Z_TYPE_P(zv) == IS_STRING && Z_STRLEN_P(zv) > 0) {
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    ob_write_varint(b, (uint64_t)Z_STRLEN_P(zv));
                    ob_write_bytes(b, Z_STRVAL_P(zv), Z_STRLEN_P(zv));
                }
                break;
            }
            case FK_SUBMSG: {
                if (Z_TYPE_P(zv) == IS_OBJECT && f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < g_schema_count) {
                    const omnibuf_schema_t *sub = &g_schemas[f->sub_schema_id];
                    zend_object *sub_obj = Z_OBJ_P(zv);
                    ob_write_bytes(b, f->tag_bytes, f->tag_len);
                    if (UNEXPECTED(allow_4b && ob_min_body_object(sub, sub_obj) > 16383)) {
                        size_t lp = ob_begin_container_4b(b);
                        ob_encode_object(b, sub, sub_obj, 1);
                        ob_end_container_4b(b, lp);
                    } else {
                        size_t lp = ob_begin_container(b);
                        ob_encode_object(b, sub, sub_obj, 0);
                        ob_end_container(b, lp);
                    }
                }
                break;
            }
            case FK_ARR_STRING: {
                if (Z_TYPE_P(zv) == IS_ARRAY) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        ob_write_bytes(b, f->tag_bytes, f->tag_len);
                        size_t lp = ob_begin_container(b);
                        uint8_t et = WT_STRING;
                        ob_write_bytes(b, &et, 1);
                        ob_write_varint(b, cnt);
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_STRING) {
                                ob_write_varint(b, (uint64_t)Z_STRLEN_P(elem));
                                ob_write_bytes(b, Z_STRVAL_P(elem), Z_STRLEN_P(elem));
                            } else {
                                ob_write_varint(b, 0);
                            }
                        } ZEND_HASH_FOREACH_END();
                        ob_end_container(b, lp);
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
                        ob_write_bytes(b, f->tag_bytes, f->tag_len);
                        size_t lp = ob_begin_container(b);
                        uint8_t et = WT_UINT;
                        ob_write_bytes(b, &et, 1);
                        ob_write_varint(b, cnt);
                        zval *elem;
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            uint64_t v = (Z_TYPE_P(elem) == IS_LONG)
                                ? (f->kind == FK_ARR_UINT32 ? (uint64_t)(uint32_t)Z_LVAL_P(elem) : (uint64_t)Z_LVAL_P(elem))
                                : 0;
                            ob_write_varint(b, v);
                        } ZEND_HASH_FOREACH_END();
                        ob_end_container(b, lp);
                    }
                }
                break;
            }
            case FK_ARR_SUBMSG: {
                if (Z_TYPE_P(zv) == IS_ARRAY && f->sub_schema_id >= 0 && (uint32_t)f->sub_schema_id < g_schema_count) {
                    HashTable *ht = Z_ARRVAL_P(zv);
                    uint32_t cnt = zend_hash_num_elements(ht);
                    if (cnt > 0) {
                        const omnibuf_schema_t *sub = &g_schemas[f->sub_schema_id];
                        int arr_4b = 0;
                        zval *elem;
                        if (UNEXPECTED(allow_4b)) {
                            size_t arr_min = 0;
                            ZEND_HASH_FOREACH_VAL(ht, elem) {
                                ZVAL_DEREF(elem);
                                if (Z_TYPE_P(elem) == IS_OBJECT) {
                                    arr_min += ob_min_body_object(sub, Z_OBJ_P(elem));
                                }
                            } ZEND_HASH_FOREACH_END();
                            arr_4b = arr_min > 16383;
                        }

                        ob_write_bytes(b, f->tag_bytes, f->tag_len);
                        size_t lp = arr_4b ? ob_begin_container_4b(b) : ob_begin_container(b);
                        uint8_t et = WT_SUBMSG;
                        ob_write_bytes(b, &et, 1);
                        ob_write_varint(b, cnt);
                        ZEND_HASH_FOREACH_VAL(ht, elem) {
                            ZVAL_DEREF(elem);
                            if (Z_TYPE_P(elem) == IS_OBJECT) {
                                zend_object *eobj = Z_OBJ_P(elem);
                                if (UNEXPECTED(arr_4b && ob_min_body_object(sub, eobj) > 16383)) {
                                    size_t elp = ob_begin_container_4b(b);
                                    ob_encode_object(b, sub, eobj, 1);
                                    ob_end_container_4b(b, elp);
                                } else {
                                    size_t elp = ob_begin_container(b);
                                    ob_encode_object(b, sub, eobj, 0);
                                    ob_end_container(b, elp);
                                }
                            } else {
                                size_t elp = ob_begin_container(b);
                                ob_end_container(b, elp);
                            }
                        } ZEND_HASH_FOREACH_END();
                        if (arr_4b) {
                            ob_end_container_4b(b, lp);
                        } else {
                            ob_end_container(b, lp);
                        }
                    }
                }
                break;
            }
        }
    }
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

static int ob_decode_object(const uint8_t *data, size_t len, const omnibuf_schema_t *s, zval *out_obj, int depth) {
    if (UNEXPECTED(depth > 32)) return -1;
    object_init_ex(out_obj, s->ce);
    zend_object *zobj = Z_OBJ_P(out_obj);

    size_t pos = 0;
    while (pos < len) {
        uint64_t tag;
        size_t tn = ob_read_varint(data, len, pos, &tag);
        if (UNEXPECTED(tn == 0)) return -1;
        pos += tn;

        uint64_t field_num = tag >> 4;
        uint8_t wt = (uint8_t)(tag & 0x0F);

        const omnibuf_field_t *f = NULL;
        if (field_num <= s->max_field_num) {
            int16_t idx = s->field_lookup[field_num];
            if (idx >= 0) f = &s->fields[idx];
        }

        if (f == NULL) {
            /* Skip unknown field */
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
                    if (UNEXPECTED(pos + 4 > len)) return -1;
                    pos += 4;
                    break;
                case WT_FIXED64:
                case WT_SFIXED64:
                case WT_FLOAT64:
                    if (UNEXPECTED(pos + 8 > len)) return -1;
                    pos += 8;
                    break;
                default:
                    sn = ob_read_varint(data, len, pos, &skip_v);
                    if (UNEXPECTED(sn == 0 || skip_v > len - pos - sn)) return -1;
                    pos += sn + (size_t)skip_v;
                    break;
            }
            continue;
        }

        zval *slot = OBJ_PROP(zobj, f->prop_offset);
        switch (f->kind) {
            case FK_UINT32:
            case FK_UINT64: {
                uint64_t v;
                size_t n = ob_read_varint(data, len, pos, &v);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                ZVAL_LONG(slot, (zend_long)v);
                break;
            }
            case FK_SINT32:
            case FK_SINT64: {
                uint64_t u;
                size_t n = ob_read_varint(data, len, pos, &u);
                if (UNEXPECTED(n == 0)) return -1;
                pos += n;
                int64_t sv = (int64_t)(u >> 1) ^ -(int64_t)(u & 1);
                ZVAL_LONG(slot, (zend_long)sv);
                break;
            }
            case FK_BOOL: {
                ZVAL_BOOL(slot, wt == WT_BOOL_TRUE);
                break;
            }
            case FK_FLOAT32: {
                if (UNEXPECTED(pos + 4 > len)) return -1;
                float fv;
                memcpy(&fv, data + pos, 4);
                pos += 4;
                ZVAL_DOUBLE(slot, (double)fv);
                break;
            }
            case FK_FLOAT64: {
                if (UNEXPECTED(pos + 8 > len)) return -1;
                double dv;
                memcpy(&dv, data + pos, 8);
                pos += 8;
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
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen > len - pos - n)) return -1;
                pos += n;
                zval sub_zv;
                if (UNEXPECTED(ob_decode_object(data + pos, (size_t)blen, &g_schemas[f->sub_schema_id], &sub_zv, depth + 1) != 0)) {
                    return -1;
                }
                zval_ptr_dtor(slot);
                ZVAL_COPY_VALUE(slot, &sub_zv);
                pos += (size_t)blen;
                break;
            }
            case FK_ARR_STRING: {
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                if (UNEXPECTED(pos >= end)) return -1;
                pos++; /* elem_wt */
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                zval arr;
                array_init_size(&arr, (uint32_t)cnt);
                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t elen;
                    size_t en = ob_read_varint(data, end, pos, &elen);
                    if (UNEXPECTED(en == 0 || elen > end - pos - en)) {
                        zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += en;
                    if (UNEXPECTED(!omnibuf_rs_validate_utf8(data + pos, (size_t)elen))) {
                        zval_ptr_dtor(&arr);
                        return -1;
                    }
                    add_next_index_stringl(&arr, (const char *)(data + pos), (size_t)elen);
                    pos += (size_t)elen;
                }
                zval_ptr_dtor(slot);
                ZVAL_COPY_VALUE(slot, &arr);
                pos = end;
                break;
            }
            case FK_ARR_UINT32:
            case FK_ARR_UINT64: {
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                if (UNEXPECTED(pos >= end)) return -1;
                pos++; /* elem_wt */
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                zval arr;
                array_init_size(&arr, (uint32_t)cnt);
                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t v;
                    size_t vn = ob_read_varint(data, end, pos, &v);
                    if (UNEXPECTED(vn == 0)) {
                        zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += vn;
                    add_next_index_long(&arr, (zend_long)v);
                }
                zval_ptr_dtor(slot);
                ZVAL_COPY_VALUE(slot, &arr);
                pos = end;
                break;
            }
            case FK_ARR_SUBMSG: {
                uint64_t blen;
                size_t n = ob_read_varint(data, len, pos, &blen);
                if (UNEXPECTED(n == 0 || blen > len - pos - n)) return -1;
                pos += n;
                size_t end = pos + (size_t)blen;
                if (UNEXPECTED(pos >= end)) return -1;
                pos++; /* elem_wt */
                uint64_t cnt;
                size_t cn = ob_read_varint(data, end, pos, &cnt);
                if (UNEXPECTED(cn == 0)) return -1;
                pos += cn;
                const omnibuf_schema_t *sub = &g_schemas[f->sub_schema_id];
                zval arr;
                array_init_size(&arr, (uint32_t)cnt);
                for (uint64_t k = 0; k < cnt; k++) {
                    uint64_t elen;
                    size_t en = ob_read_varint(data, end, pos, &elen);
                    if (UNEXPECTED(en == 0 || elen > end - pos - en)) {
                        zval_ptr_dtor(&arr);
                        return -1;
                    }
                    pos += en;
                    zval elem_zv;
                    if (UNEXPECTED(ob_decode_object(data + pos, (size_t)elen, sub, &elem_zv, depth + 1) != 0)) {
                        zval_ptr_dtor(&arr);
                        return -1;
                    }
                    add_next_index_zval(&arr, &elem_zv);
                    pos += (size_t)elen;
                }
                zval_ptr_dtor(slot);
                ZVAL_COPY_VALUE(slot, &arr);
                pos = end;
                break;
            }
        }
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

    if (g_schema_count >= MAX_SCHEMAS) {
        zend_throw_exception(zend_ce_exception, "omnibuf: max schemas exceeded", 0);
        RETURN_THROWS();
    }

    zend_class_entry *ce = zend_lookup_class(class_name);
    if (!ce) {
        zend_throw_exception_ex(zend_ce_exception, 0, "omnibuf: class %s not found", ZSTR_VAL(class_name));
        RETURN_THROWS();
    }

    uint32_t id = g_schema_count++;
    omnibuf_schema_t *s = &g_schemas[id];
    s->ce = ce;
    s->field_count = zend_hash_num_elements(fields_ht);
    s->fields = (omnibuf_field_t *)calloc(s->field_count, sizeof(omnibuf_field_t));
    s->max_field_num = 0;

    uint32_t idx = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(fields_ht, entry) {
        if (Z_TYPE_P(entry) != IS_ARRAY) continue;
        HashTable *fht = Z_ARRVAL_P(entry);
        zval *znum = zend_hash_index_find(fht, 0);
        zval *zkind = zend_hash_index_find(fht, 1);
        zval *zprop = zend_hash_index_find(fht, 2);
        zval *zsub = zend_hash_index_find(fht, 3);

        omnibuf_field_t *f = &s->fields[idx];
        f->field_num = (uint32_t)Z_LVAL_P(znum);
        f->kind = (uint8_t)Z_LVAL_P(zkind);
        f->sub_schema_id = zsub && Z_TYPE_P(zsub) == IS_LONG ? (int32_t)Z_LVAL_P(zsub) : -1;

        zend_property_info *pinfo = zend_hash_find_ptr(&ce->properties_info, Z_STR_P(zprop));
        if (!pinfo) {
            zend_throw_exception_ex(zend_ce_exception, 0, "omnibuf: property %s not found on %s", Z_STRVAL_P(zprop), ZSTR_VAL(class_name));
            RETURN_THROWS();
        }
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

        if (f->field_num > s->max_field_num) s->max_field_num = f->field_num;
        idx++;
    } ZEND_HASH_FOREACH_END();

    s->field_lookup = (int16_t *)malloc((s->max_field_num + 1) * sizeof(int16_t));
    for (uint32_t i = 0; i <= s->max_field_num; i++) s->field_lookup[i] = -1;
    s->scalar_max_bytes = 0;
    s->var_field_indices = (uint32_t *)malloc(s->field_count * sizeof(uint32_t));
    s->var_field_count = 0;
    for (uint32_t i = 0; i < s->field_count; i++) {
        const omnibuf_field_t *f = &s->fields[i];
        s->field_lookup[f->field_num] = (int16_t)i;
        switch (f->kind) {
            case FK_UINT32: case FK_SINT32: s->scalar_max_bytes += f->tag_len + 9; break;
            case FK_UINT64: case FK_SINT64: s->scalar_max_bytes += f->tag_len + 9; break;
            case FK_BOOL: s->scalar_max_bytes += f->tag_len; break;
            case FK_FLOAT32: s->scalar_max_bytes += f->tag_len + 4; break;
            case FK_FLOAT64: s->scalar_max_bytes += f->tag_len + 8; break;
            default:
                s->var_field_indices[s->var_field_count++] = i;
                break;
        }
    }

    RETURN_LONG((zend_long)id);
}

PHP_FUNCTION(omnibuf_native_encode) {
    zend_long schema_id;
    zval *obj;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(schema_id)
        Z_PARAM_OBJECT(obj)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(schema_id < 0 || (uint32_t)schema_id >= g_schema_count)) {
        zend_throw_exception(zend_ce_exception, "omnibuf: invalid schema id", 0);
        RETURN_THROWS();
    }

    const omnibuf_schema_t *s = &g_schemas[schema_id];
    zend_object *zobj = Z_OBJ_P(obj);
    size_t max_len = ob_estimate_object(s, zobj, NULL);

    /* Allocate the target zend_string once and encode directly into it (zero intermediate copy). */
    zend_string *zstr = zend_string_alloc(max_len, 0);
    ob_buf_t b;
    b.data = (uint8_t *)ZSTR_VAL(zstr);
    b.len = 0;
    b.cap = max_len;
    b.heap = 0;

    ob_encode_object(&b, s, zobj, max_len > 16383);

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

    if (UNEXPECTED(schema_id < 0 || (uint32_t)schema_id >= g_schema_count)) {
        zend_throw_exception(zend_ce_exception, "omnibuf: invalid schema id", 0);
        RETURN_THROWS();
    }

    if (UNEXPECTED(ob_decode_object((const uint8_t *)ZSTR_VAL(bytes), ZSTR_LEN(bytes), &g_schemas[schema_id], return_value, 0) != 0)) {
        zend_throw_exception(zend_ce_exception, "omnibuf: decode error", 0);
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
    if (r != 1 || wt != WT_STRING) {
        RETURN_EMPTY_STRING();
    }
    uint64_t slen = 0;
    size_t n = ob_read_varint(base, slice_len, voff, &slen);
    if (n == 0 || slen > vlen - n) {
        RETURN_EMPTY_STRING();
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
    if (r != 1 || wt != WT_UINT) {
        RETURN_LONG(0);
    }
    uint64_t val = 0;
    if (ob_read_varint(base, slice_len, voff, &val) == 0) {
        RETURN_LONG(0);
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
    if (r != 1 || wt != WT_SUBMSG) {
        RETURN_NULL();
    }
    uint64_t blen = 0;
    size_t n = ob_read_varint(base, slice_len, voff, &blen);
    if (n == 0 || blen > vlen - n) {
        RETURN_NULL();
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
    zval *val;
    ZEND_HASH_FOREACH_NUM_KEY_VAL(dirty_ht, num_key, val) {
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
        zend_throw_exception(zend_ce_exception, "omnibuf: splice failed on malformed packet", 0);
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
        zend_throw_exception(zend_ce_exception, "omnibuf: blind decode error", 0);
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

PHP_MSHUTDOWN_FUNCTION(omnibuf) {
    for (uint32_t i = 0; i < g_schema_count; i++) {
        free(g_schemas[i].fields);
        free(g_schemas[i].field_lookup);
        free(g_schemas[i].var_field_indices);
    }
    g_schema_count = 0;
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
    PHP_MSHUTDOWN(omnibuf),
    NULL,
    NULL,
    PHP_MINFO(omnibuf),
    "0.1.0",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_OMNIBUF
ZEND_GET_MODULE(omnibuf)
#endif
