<?php

declare(strict_types=1);

namespace OmniBuf;

use Google\Protobuf\Internal\Message;

/**
 * Unified public facade for OmniBuf v1 DRAFT-1 across all 4 tiers.
 */
final class OmniBuf
{
    public const string VERSION = '0.1.6';

    /**
     * Serializes a Tier 1 generated message, Tier 2 google/protobuf message, or Tier 3 attributed class.
     */
    public static function marshal(
        object $message,
        bool $deterministic = false,
        bool $selfDescribing = false,
    ): string {
        if ($message instanceof GeneratedMessage) {
            return $message->marshalOmnibuf($deterministic, $selfDescribing);
        }
        if ($message instanceof Message) {
            return ProtobufCodec::marshal($message, $deterministic, $selfDescribing);
        }
        return AttributeCodec::marshal($message, $deterministic, $selfDescribing);
    }

    /**
     * Decodes OmniBuf wire bytes into a Tier 1 generated message, Tier 2 google/protobuf message,
     * or Tier 3 attributed class (class-string or existing instance).
     *
     * @template T of object
     * @param class-string<T>|T $target
     * @return T
     */
    public static function unmarshal(
        string $data,
        string|object $target,
        bool $merge = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): object {
        if (\is_string($target)) {
            if (is_subclass_of($target, GeneratedMessage::class)) {
                /** @var T */
                return $target::unmarshalOmnibuf($data, $maxDepth, $maxElements);
            }
            if (is_subclass_of($target, Message::class)) {
                return ProtobufCodec::unmarshal($data, $target, $merge, $maxDepth, $maxElements);
            }
            return AttributeCodec::unmarshal($data, $target, $merge, $maxDepth, $maxElements);
        }

        if ($target instanceof GeneratedMessage) {
            $target->unmarshalInto($data, $merge, $maxDepth, $maxElements);
            return $target;
        }
        if ($target instanceof Message) {
            return ProtobufCodec::unmarshal($data, $target, $merge, $maxDepth, $maxElements);
        }
        return AttributeCodec::unmarshal($data, $target, $merge, $maxDepth, $maxElements);
    }

    /**
     * Tier 4: Schema-less blind decode returning a PHP associative array.
     *
     * @return array<string, mixed>
     */
    public static function decodeBlind(
        string $packet,
        bool $bytesAsBase64 = false,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): array {
        return BlindDecoder::decode($packet, $bytesAsBase64, $maxDepth, $maxElements);
    }

    /**
     * Tier 4: Schema-less blind decode returning deterministic JSON.
     */
    public static function decodeBlindJson(
        string $packet,
        bool $allowNan = false,
        bool $fallbackOnNonFinite = false,
        int $indent = 0,
        int $maxDepth = Wire::MAX_NESTING_DEPTH,
        int $maxElements = Wire::DEFAULT_MAX_ELEMENTS,
    ): string {
        if (
            !$allowNan
            && !$fallbackOnNonFinite
            && $indent === 0
            && $maxDepth === Wire::MAX_NESTING_DEPTH
            && $maxElements === Wire::DEFAULT_MAX_ELEMENTS
            && \function_exists('omnibuf_native_decode_blind_json')
        ) {
            try {
                /** @var string $json */
                $json = \omnibuf_native_decode_blind_json($packet);
                return $json;
            } catch (\Throwable $e) {
                throw new Exception\DecodeException($e->getMessage(), 0, $e);
            }
        }
        return BlindDecoder::decodeJson($packet, $allowNan, $fallbackOnNonFinite, $indent, $maxDepth, $maxElements);
    }
}
