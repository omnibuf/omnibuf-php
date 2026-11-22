PHP_ARG_ENABLE([omnibuf],
  [whether to enable omnibuf native accelerator],
  [AS_HELP_STRING([--enable-omnibuf],
    [Enable omnibuf native Rust-backed accelerator])],
  [no])

if test "$PHP_OMNIBUF" != "no"; then
  OMNIBUF_RS_LIB="$abs_srcdir/rust/target/release/libomnibuf_php_rs.a"
  if test ! -f "$OMNIBUF_RS_LIB"; then
    AC_MSG_ERROR([Missing $OMNIBUF_RS_LIB; run 'cargo build --release --manifest-path ext/rust/Cargo.toml' first])
  fi
  PHP_ADD_LIBRARY_WITH_PATH(omnibuf_php_rs, $abs_srcdir/rust/target/release, OMNIBUF_SHARED_LIBADD)
  PHP_SUBST(OMNIBUF_SHARED_LIBADD)
  PHP_NEW_EXTENSION(omnibuf, omnibuf.c, $ext_shared)
fi
