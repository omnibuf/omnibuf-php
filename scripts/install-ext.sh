#!/bin/sh
set -eu

REPO="omnibuf/omnibuf-php"
VERSION="${VERSION:-latest}"

PHP_BIN="${PHP_BIN:-php}"
PHP_CONFIG_BIN="${PHP_CONFIG_BIN:-php-config}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "error: '$PHP_BIN' not found in PATH" >&2
  exit 1
fi

PHP_MINOR="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
OS="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH="$(uname -m)"

case "$OS" in
  linux) OS="linux" ;;
  darwin) OS="darwin" ;;
  *)
    echo "error: unsupported OS '$OS' (expected linux or darwin)" >&2
    exit 1
    ;;
esac

case "$ARCH" in
  x86_64|amd64) ARCH="x86_64" ;;
  arm64|aarch64) ARCH="arm64" ;;
  *)
    echo "error: unsupported architecture '$ARCH'" >&2
    exit 1
    ;;
esac

if [ "$VERSION" = "latest" ]; then
  TAG="$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
  if [ -z "$TAG" ]; then
    echo "error: could not determine latest release tag for ${REPO}" >&2
    exit 1
  fi
else
  TAG="$VERSION"
fi

ARCHIVE="ext-omnibuf-${TAG}-php${PHP_MINOR}-${OS}-${ARCH}.tar.gz"
BASE_URL="https://github.com/${REPO}/releases/download/${TAG}"

TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

echo "Downloading ${ARCHIVE}..."
curl -fsSL "${BASE_URL}/${ARCHIVE}" -o "${TMPDIR}/${ARCHIVE}"
curl -fsSL "${BASE_URL}/${ARCHIVE}.sha256" -o "${TMPDIR}/${ARCHIVE}.sha256"

EXPECTED_SHA="$(awk '{print $1}' "${TMPDIR}/${ARCHIVE}.sha256")"
if command -v sha256sum >/dev/null 2>&1; then
  ACTUAL_SHA="$(sha256sum "${TMPDIR}/${ARCHIVE}" | awk '{print $1}')"
else
  ACTUAL_SHA="$(shasum -a 256 "${TMPDIR}/${ARCHIVE}" | awk '{print $1}')"
fi

if [ "$EXPECTED_SHA" != "$ACTUAL_SHA" ]; then
  echo "error: SHA-256 mismatch for ${ARCHIVE}" >&2
  exit 1
fi

tar -xzf "${TMPDIR}/${ARCHIVE}" -C "$TMPDIR"

if [ -n "${EXT_DIR:-}" ]; then
  TARGET_DIR="$EXT_DIR"
elif command -v "$PHP_CONFIG_BIN" >/dev/null 2>&1; then
  TARGET_DIR="$("$PHP_CONFIG_BIN" --extension-dir)"
else
  TARGET_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
fi

mkdir -p "$TARGET_DIR"
if [ -w "$TARGET_DIR" ]; then
  cp "${TMPDIR}/omnibuf.so" "${TARGET_DIR}/omnibuf.so"
else
  echo "Installing into ${TARGET_DIR} (requires sudo)..."
  sudo cp "${TMPDIR}/omnibuf.so" "${TARGET_DIR}/omnibuf.so"
fi

echo "Installed ${TARGET_DIR}/omnibuf.so"
echo "Enable it in php.ini with: extension=omnibuf"
