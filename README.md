# OmniBuf for PHP

PHP 8.3+ implementation of the [OmniBuf v0.1 wire format](https://omnibuf.com/spec).

OmniBuf is a binary wire format for numbered fields with explicit 4-bit wire types, 1/2/4/9-byte Prefix-Varints, length-prefixed containers for `O(1)` field skipping, optional Field #0 (`SchemaMeta`) naming metadata, and lazy zero-decode `Opaque<T>` views with in-place wire splicing.

## Installation

```bash
composer require omnibuf/omnibuf
```

Requires PHP 8.3 or newer (64-bit).

## Four Integration Tiers

1. **Tier 1 (`protoc-gen-omnibuf-php`):** Generates typed PHP 8.3+ message classes with single-pass forward encoders/decoders and lazy `Opaque<Message>` views for `O(fields)` field reads and wire splicing.
2. **Tier 2 (`google/protobuf` PHP):** Marshals and unmarshals standard `Google\Protobuf\Internal\Message` classes via `DescriptorPool` with no plugin required.
3. **Tier 3 (PHP 8 Attributes):** Reflection-cached codec for plain PHP classes annotated with `#[OmniBufMessage]` and `#[OmniBufField]`.
4. **Tier 4 (Blind Decode):** Schema-less `OmniBuf::decodeBlind()` and `OmniBuf::decodeBlindJson()` supporting Field #0 `SchemaMeta` names with `field_<N>` fallback keys.

## Quick Start

### Tier 3: PHP 8 Attributes

```php
use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use OmniBuf\OmniBuf;

#[OmniBufMessage('Location')]
final class Location
{
    #[OmniBufField(3, 'string')]
    public string $city = '';

    #[OmniBufField(4, 'uint32')]
    public int $rack = 0;
}

#[OmniBufMessage('Node')]
final class Node
{
    #[OmniBufField(1, 'uint32')]
    public int $id = 0;

    #[OmniBufField(3, 'bool')]
    public bool $active = false;

    #[OmniBufField(9, 'message', messageClass: Location::class)]
    public ?Location $location = null;
}

$node = new Node();
$node->id = 300;
$node->active = true;
$node->location = new Location();
$node->location->city = 'FRA';
$node->location->rack = 42;

$bytes = OmniBuf::marshal($node, deterministic: true, selfDescribing: true);
$decoded = OmniBuf::unmarshal($bytes, Node::class);
$json = OmniBuf::decodeBlindJson($bytes);
```

### Tier 1: Code Generation & Lazy `Opaque<T>` Splicing

Generate classes with `buf generate` using `bin/protoc-gen-omnibuf-php`:

```php
use OmniBuf\Generated\Omnibuf\Bench\OpaquePeerUpdate;

$view = new OpaquePeerUpdate($packetBytes);
if ($view->opaqueLocation()?->getCity() === 'Frankfurt') {
    $view->setSequence($view->getSequence() + 1);
}
$updatedPacket = $view->marshal();
```

### Tier 2: `google/protobuf` Messages

```php
use OmniBuf\OmniBuf;
use Omnibuf\Bench\PeerUpdate;

$bytes = OmniBuf::marshal($protoPeerUpdate, deterministic: true);
$decoded = OmniBuf::unmarshal($bytes, PeerUpdate::class);
```

## Benchmarks

PHP benchmark results, methodology, and cross-language comparisons live at [omnibuf.com/benchmarks?lang=php](https://omnibuf.com/benchmarks?lang=php) (all languages at [omnibuf.com/benchmarks](https://omnibuf.com/benchmarks)). Run `just bench` locally to regenerate [`testdata/benchmarks.json`](testdata/benchmarks.json).

## Development & Verification

```bash
just install      # Install Composer dependencies
just proto        # Lint .proto files and regenerate PHP classes
just fmt-check    # Check PER-CS2.0 formatting via php-cs-fixer
just lint         # Run buf lint and PHPStan (level 6)
just test         # Run PHPUnit unit tests
just conformance  # Run the 992-case cross-language conformance suite
just bench        # Run the 6-category PeerUpdate benchmark suite
just ci           # Run all verification gates
```

## License

Apache License 2.0. See [LICENSE](LICENSE).
