<?php

declare(strict_types=1);

namespace OmniBuf\Grpc;

use OmniBuf\OmniBuf;
use OmniBuf\Wire;

/**
 * Detached gRPC codec helpers for OmniBuf wire payloads with no grpc/grpc package dependency.
 */
final class GrpcCodec
{
    public const string CODEC_NAME = 'omnibuf';
    public const string SELF_DESCRIBING_CODEC_NAME = 'x-omnibuf';
    public const string CONTENT_TYPE_GRPC = 'application/grpc+omnibuf';

    /**
     * Serializes an OmniBuf message (generated, google/protobuf, or attributed class) into wire bytes.
     */
    public static function serialize(
        object $msg,
        bool $deterministic = false,
        bool $selfDescribing = false,
    ): string {
        return OmniBuf::marshal($msg, $deterministic, $selfDescribing);
    }

    /**
     * Returns a deserializer Closure suitable for Grpc\BaseStub calls.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return \Closure(string): T
     */
    public static function deserialize(
        string $class,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): callable {
        return static function (string $data) use ($class, $maxDepth, $maxElements): object {
            return OmniBuf::unmarshal($data, $class, false, $maxDepth, $maxElements);
        };
    }

    /**
     * Returns gRPC call metadata selecting the OmniBuf content-type header for Grpc\BaseStub.
     *
     * @return array<string, list<string>>
     */
    public static function callMetadata(bool $selfDescribing = false): array
    {
        $contentType = $selfDescribing
            ? 'application/grpc+' . self::SELF_DESCRIBING_CODEC_NAME
            : self::CONTENT_TYPE_GRPC;

        return [
            'content-type' => [$contentType],
        ];
    }
}
