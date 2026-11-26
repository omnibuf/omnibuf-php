default: ci

# Install PHP dependencies via Composer
install:
    composer install

# Lint .proto schemas and regenerate OmniBuf + google/protobuf PHP classes
proto:
    buf lint
    buf generate

# Alias for proto code generation
generate: proto

# Format PHP source, tests, bins, and benchmarks
fmt:
    vendor/bin/php-cs-fixer fix

# Check formatting without modifying files
fmt-check:
    vendor/bin/php-cs-fixer fix --dry-run --diff

# Run buf lint and PHPStan static analysis
lint:
    buf lint
    vendor/bin/phpstan analyse --memory-limit=512M

# Run unit tests with PHPUnit
test:
    vendor/bin/phpunit

# Run the language-independent OmniBuf conformance suite across all 4 tiers
conformance:
    #!/usr/bin/env bash
    set -euo pipefail
    if [[ -n "${CONFORMANCE_RUNNER:-}" ]]; then
        RUNNER="$CONFORMANCE_RUNNER"
    elif [[ -x "../omnibuf-go/bin/conformance-runner" ]]; then
        RUNNER="../omnibuf-go/bin/conformance-runner"
    elif command -v conformance-runner >/dev/null 2>&1; then
        RUNNER="conformance-runner"
    elif command -v go >/dev/null 2>&1; then
        exec go run github.com/omnibuf/omnibuf-go/cmd/conformance-runner@latest php bin/conformance-php
    else
        echo "conformance-runner binary not found; set CONFORMANCE_RUNNER or install conformance-runner" >&2
        exit 1
    fi
    "$RUNNER" php bin/conformance-php

# Build optional 100% Rust ext-omnibuf PHP extension (ext-php-rs)
ext-build:
    cd ext/rust && cargo build --release
    mkdir -p ext/modules
    if [ -f ext/rust/target/release/libomnibuf.dylib ]; then cp ext/rust/target/release/libomnibuf.dylib ext/modules/omnibuf.so; else cp ext/rust/target/release/libomnibuf.so ext/modules/omnibuf.so; fi

# Run the language-independent OmniBuf conformance suite with ext-omnibuf loaded
conformance-native: ext-build
    #!/usr/bin/env bash
    set -euo pipefail
    if [[ -n "${CONFORMANCE_RUNNER:-}" ]]; then
        RUNNER="$CONFORMANCE_RUNNER"
    elif [[ -x "../omnibuf-go/bin/conformance-runner" ]]; then
        RUNNER="../omnibuf-go/bin/conformance-runner"
    elif command -v conformance-runner >/dev/null 2>&1; then
        RUNNER="conformance-runner"
    elif command -v go >/dev/null 2>&1; then
        exec go run github.com/omnibuf/omnibuf-go/cmd/conformance-runner@latest php -d extension=ext/modules/omnibuf.so bin/conformance-php
    else
        echo "conformance-runner binary not found; set CONFORMANCE_RUNNER or install conformance-runner" >&2
        exit 1
    fi
    "$RUNNER" php -d extension=ext/modules/omnibuf.so bin/conformance-php

# Run the reproducible PeerUpdate benchmark suite and write testdata/benchmarks.json
bench:
    php benchmarks/bench.php

# Run all formatting, linting, unit test, and conformance gates
ci: fmt-check lint test conformance
