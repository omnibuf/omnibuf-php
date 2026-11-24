# omnibuf-php

[![OmniBuf Spec: v1 DRAFT-1](https://img.shields.io/badge/OmniBuf%20Spec-v1%20DRAFT--1-FF4F18)](https://omnibuf.com/spec)

PHP 8.3+ (64-bit) implementation of the [OmniBuf v1 DRAFT-1 wire specification](https://omnibuf.com/spec). Documentation and benchmarks live at [omnibuf.com/php](https://omnibuf.com/php).

## Install

```bash
composer require omnibuf/omnibuf
```

## Quick Usage

```php
use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use OmniBuf\OmniBuf;

#[OmniBufMessage('Node')]
final class Node
{
    #[OmniBufField(1, 'uint32')]
    public int $id = 0;

    #[OmniBufField(2, 'string')]
    public string $city = '';

    #[OmniBufField(3, 'bool')]
    public bool $active = false;
}

$node = new Node();
$node->id = 300;
$node->city = 'FRA';
$node->active = true;

$bytes = OmniBuf::marshal($node, deterministic: true, selfDescribing: true);
$decoded = OmniBuf::unmarshal($bytes, Node::class);
```

## Implementation Details

- **PHP 8.3+ attributes**: `#[OmniBufMessage]` and `#[OmniBufField]` drive a reflection-cached codec over plain PHP classes.
- **Unsigned 64-bit integers**: `OmniBuf\UInt64` handles full-range `uint64` and `fixed64` values beyond PHP's signed `int` range.
- **`google/protobuf` interop**: `OmniBuf::marshal` and `OmniBuf::unmarshal` serialize `Google\Protobuf\Internal\Message` classes via `DescriptorPool`.
- **Codegen**: `bin/protoc-gen-omnibuf-php` generates typed message classes and lazy `Opaque` views for in-place wire splicing.
- **Optional native extension**: `ext/` contains an optional C/Rust PHP extension for native wire acceleration.
- **Blind decode**: `OmniBuf::decodeBlind()` and `OmniBuf::decodeBlindJson()` inspect packets using `Field #0` names or `field_<N>` fallbacks.

## gRPC Integration

`OmniBuf\Grpc\GrpcCodec` provides detached helpers for `grpc/grpc` clients with no extra Composer dependency:

```php
use OmniBuf\Grpc\GrpcCodec;

$wire = GrpcCodec::serialize($request, deterministic: true);
$deserializer = GrpcCodec::deserialize(Node::class);
$metadata = GrpcCodec::callMetadata(); // ['content-type' => ['application/grpc+omnibuf']]

// Inside a Grpc\BaseStub subclass:
// $this->_simpleRequest('/pkg.Svc/GetNode', $request, $deserializer, $metadata);
```

## Development

```bash
just install && just test && just conformance
```

## License

Apache-2.0. See [LICENSE](./LICENSE).
